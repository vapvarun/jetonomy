<?php
/**
 * One-time remediation for user data the pre-1.8.0 purge gap left behind.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and purges ORPHANED user data — rows still pointing at a wp_users row
 * that no longer exists.
 *
 * Why this class exists at all
 * ---------------------------
 * 1.7.1 closed a genuine GDPR hole: `jt_pro_ai_log` (raw AI prompts and
 * responses) was never touched by either the eraser or the delete path, and on
 * multisite neither `wpmu_delete_user()` nor `remove_user_from_blog()` fired
 * `delete_user` at all, so a network-admin deletion cleaned nothing. Both are
 * fixed — but ONLY GOING FORWARD. Every account deleted before that release
 * left its rows sitting in the database, and they are still there.
 *
 * "We stopped creating new violations" is not the same as "we are compliant".
 * A site owner who ran an erasure request before 1.7.1 was told the data was
 * gone; it is not. Closing the hole does not remediate what already leaked
 * through it, so the leaked data has to be swept up explicitly. That is this
 * class.
 *
 * On-demand, not automatic
 * ------------------------
 * This runs ONLY when a site owner asks for it, via `wp jetonomy privacy scan`
 * and `wp jetonomy privacy purge-orphans`. It is deliberately NOT wired to an
 * upgrade migration or a scheduled job. An auto-sweep armed by the free
 * plugin's migration cannot see Pro's tables unless Pro is loaded at that
 * instant — and WordPress updates plugins one at a time, so free-before-Pro is
 * the normal order. An auto-sweep that ran in that window would find no
 * `jt_pro_ai_log` rows, declare the site clean, and disarm — leaving the exact
 * leak this class exists to remove, permanently, while telling the owner it was
 * gone. A CLI command has no such race: anyone running `wp jetonomy` has both
 * plugins loaded, so discovery always sees the full column set.
 *
 * Why it replays the purge instead of writing its own
 * ---------------------------------------------------
 * The obvious implementation — a list of "orphan cleanup" DELETE statements —
 * is the same mistake the original bug was made of: a second list, next to
 * PURGE_TABLES, free to drift away from it the first time someone adds a table
 * to one and not the other. That is exactly how `ai_log` was missed.
 *
 * So there is no second list. Discovery derives its columns FROM the live
 * purge lists ({@see Privacy::orphan_columns()}), and remediation fires
 * `jetonomy_purge_orphan_user`, which free's and Pro's existing
 * `on_user_delete()` bodies already listen to. The backfill IS the forward
 * fix, replayed against the users it never got to run for — counter recompute,
 * cache busting and all. A table added to PURGE_TABLES tomorrow is swept by
 * this code automatically, with no edit here.
 *
 * We deliberately do NOT fire core's `delete_user`: the accounts are already
 * gone, and other plugins listening to it would be handed a user id that no
 * longer resolves.
 *
 * Scale
 * -----
 * The load-bearing insight is that we discover USERS, not ROWS. A 500k-row
 * `jt_pro_ai_log` still only holds as many distinct `user_id` values as the
 * site has members, so discovery is a `DISTINCT` walk of the `user_id` index
 * (bounded by user count, not row count) plus one PK lookup per distinct
 * value — seconds, not minutes, and no row bodies are read. Nothing is ever
 * loaded into memory beyond the orphan id list, which is itself capped at
 * {@see DISCOVERY_CAP} per pass. The purge then runs one user at a time under
 * a wall-clock budget; {@see run_batch()} drains a durable queue a slice at a
 * time, and the CLI command loops it until the site is clean, so no single
 * call carries the whole sweep.
 */
final class Privacy_Backfill {

	/** Durable queue of orphan user ids still to purge across run_batch() calls. */
	private const QUEUE_OPTION = 'jetonomy_orphan_purge_queue';

	/**
	 * Max orphan ids discovered per pass.
	 *
	 * Caps the queue option's size on a site with a pathological number of
	 * deleted accounts. Draining the queue re-runs discovery, so a site with
	 * more orphans than this simply takes more passes — the sweep is still
	 * complete, it just never holds more than this many ids at once.
	 */
	private const DISCOVERY_CAP = 5000;

	/**
	 * Every (table, column) that can hold a user id, derived from the live purge
	 * lists so this can never drift from what the forward fix actually cleans.
	 *
	 * @return array<int,array{table:string,column:string,where:string}>
	 */
	public static function columns(): array {
		/**
		 * Columns scanned for orphaned user ids.
		 *
		 * Pro adds its own `jt_pro_*` columns here rather than shipping a
		 * parallel sweeper — free owns the engine, Pro contributes its tables.
		 *
		 * @param array<int,array{table:string,column:string,where:string}> $columns Discovery targets.
		 */
		$columns = apply_filters( 'jetonomy_privacy_orphan_columns', Privacy::orphan_columns() );

		$clean = [];
		foreach ( (array) $columns as $c ) {
			if ( empty( $c['table'] ) || empty( $c['column'] ) ) {
				continue;
			}
			$clean[] = [
				'table'  => (string) $c['table'],
				'column' => sanitize_key( (string) $c['column'] ),
				'where'  => (string) ( $c['where'] ?? '' ),
			];
		}
		return $clean;
	}

	/**
	 * Distinct user ids referenced by our tables that no longer exist in wp_users.
	 *
	 * `<> 0` is doing real work here and is NOT a micro-optimisation: 0 is the
	 * plugin's own "anonymized / system" sentinel — it is what BOTH the eraser
	 * and the delete path set `author_id`, `actor_id`, `created_by` etc. TO. It
	 * also covers rows a cron or system action created with no acting user.
	 * Sweeping id 0 would therefore delete the anonymized content those paths
	 * deliberately KEPT (posts, replies, messages, conversations), which is data
	 * loss, not remediation — the exact opposite of the job. NULL is excluded by
	 * the same predicate (`NULL <> 0` is NULL, never true), so nullable columns
	 * are left alone too.
	 *
	 * @param int $limit Max ids to return.
	 * @return int[]
	 */
	public static function find_orphans( int $limit = self::DISCOVERY_CAP ): array {
		global $wpdb;

		$found = [];
		foreach ( self::columns() as $target ) {
			if ( count( $found ) >= $limit ) {
				break;
			}
			if ( ! self::table_exists( $target['table'] ) ) {
				continue; // Extension never activated, or an older schema.
			}

			$col   = $target['column'];
			$tbl   = $target['table'];
			$extra = '' !== $target['where'] ? ' AND ( ' . $target['where'] . ' )' : '';

			// LEFT JOIN ... IS NULL rather than NOT IN (SELECT ID FROM wp_users):
			// the join is a PK lookup per distinct value and stays index-only on
			// the scanned side; NOT IN materializes the whole user table.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT t.{$col} FROM {$tbl} t
					 LEFT JOIN {$wpdb->users} u ON u.ID = t.{$col}
					 WHERE t.{$col} <> 0 AND u.ID IS NULL{$extra}
					 LIMIT %d",
					$limit
				)
			);

			foreach ( (array) $ids as $id ) {
				$found[ (int) $id ] = true;
			}
		}

		$found = array_map( 'intval', array_keys( $found ) );
		sort( $found );
		return array_slice( $found, 0, $limit );
	}

	/**
	 * Per-column orphan row counts — the "what would this remove" report.
	 *
	 * Read-only. Backs `wp jetonomy privacy scan` and the CLI's post-run
	 * confirmation, so an owner can see the remediation actually happened
	 * rather than taking our word for it a second time.
	 *
	 * @return array<int,array{table:string,column:string,orphan_rows:int}>
	 */
	public static function count_orphans(): array {
		global $wpdb;

		$report = [];
		foreach ( self::columns() as $target ) {
			if ( ! self::table_exists( $target['table'] ) ) {
				continue;
			}

			$col   = $target['column'];
			$tbl   = $target['table'];
			$extra = '' !== $target['where'] ? ' AND ( ' . $target['where'] . ' )' : '';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tbl} t
				 LEFT JOIN {$wpdb->users} u ON u.ID = t.{$col}
				 WHERE t.{$col} <> 0 AND u.ID IS NULL{$extra}"
			);

			if ( $n > 0 ) {
				$report[] = [
					'table'       => $tbl,
					'column'      => $col,
					'orphan_rows' => $n,
				];
			}
		}
		return $report;
	}

	/**
	 * Purge one bounded slice of the orphan queue.
	 *
	 * Idempotent by construction: purging an orphan removes the rows that made
	 * it discoverable, so a second run finds nothing and does nothing. A run
	 * interrupted half-way leaves the rest in the queue for the next one — the
	 * queue is a durable option, not a transient, because an object cache under
	 * memory pressure evicting this would silently abandon a GDPR remediation.
	 *
	 * @param float $seconds Wall-clock budget for this slice.
	 * @return array{purged:int,remaining:int}
	 */
	public static function run_batch( float $seconds = 15.0 ): array {
		$queue = get_option( self::QUEUE_OPTION );

		// No queue yet (or a previous pass drained it): discover.
		if ( ! is_array( $queue ) ) {
			$queue = self::find_orphans();
		}

		/**
		 * Seconds one orphan-purge slice may spend before stopping at a user
		 * boundary. Mirrors `jetonomy_import_batch_seconds` — same reasoning:
		 * stop cleanly before the request dies rather than die mid-user.
		 *
		 * @param float $seconds Default 15.
		 */
		$seconds  = (float) apply_filters( 'jetonomy_orphan_purge_batch_seconds', $seconds );
		$deadline = microtime( true ) + max( 1.0, $seconds );
		$purged   = 0;

		while ( $queue ) {
			$user_id = (int) array_shift( $queue );
			if ( $user_id > 0 ) {
				/**
				 * Purge every trace of a user id that no longer exists.
				 *
				 * Free's and Pro's `Privacy::on_user_delete()` both listen, so
				 * this reuses the real delete path rather than reimplementing
				 * it. Third-party code storing per-user rows can listen too.
				 *
				 * @param int $user_id The orphaned (already-deleted) user id.
				 */
				do_action( 'jetonomy_purge_orphan_user', $user_id );
				++$purged;
			}

			// Stop at a user boundary — never mid-user, so a killed request can
			// never leave one account half-purged.
			if ( $queue && microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( $queue ) {
			update_option( self::QUEUE_OPTION, array_values( $queue ), false );
		} else {
			delete_option( self::QUEUE_OPTION );
		}

		return [
			'purged'    => $purged,
			'remaining' => count( $queue ),
		];
	}

	/** Does a table exist? Pro tables are absent when the extension never ran. */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
