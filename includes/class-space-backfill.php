<?php
/**
 * One-time remediation for rows left behind by spaces deleted without a cascade.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and purges ORPHANED space data — rows still pointing at a `jt_spaces`
 * row that no longer exists.
 *
 * Why this class exists
 * ---------------------
 * `Space::delete()` has always been a bare row delete plus a cache bust.
 * Nothing cascaded. Every space ever deleted left its topics, replies, members,
 * join requests, subscriptions, votes, flags and notifications in the database,
 * pointing at an id that no longer resolves.
 *
 * That is not only wasted space. Orphaned `jt_notifications` rows can still be
 * shown to members, deep-linking them into a space that no longer exists, and
 * orphaned `jt_space_members` rows skew the counts a site owner reads. Closing
 * the cascade going forward does not remove what already leaked through it, so
 * the leaked rows have to be swept explicitly. That is this class.
 *
 * It is the same shape as {@see Privacy_Backfill}, for the same reasons, and
 * the two should stay recognisably alike — a maintainer who has read one should
 * not have to re-learn the other.
 *
 * On-demand, not automatic
 * ------------------------
 * Runs ONLY when a site owner asks, via `wp jetonomy space scan-orphans` and
 * `wp jetonomy space purge-orphans`. Deliberately NOT wired to an upgrade
 * migration or a scheduled job, for the reason {@see Privacy_Backfill}
 * documents at length: WordPress updates plugins one at a time, so an
 * auto-sweep armed by free's migration would routinely run with Pro not yet
 * loaded, see none of Pro's space-scoped tables, declare the site clean and
 * disarm — leaving exactly the rows it exists to remove, permanently, while
 * reporting success. Anyone running `wp jetonomy` has both plugins loaded.
 *
 * It is also destructive cleanup of a customer's data. That should be a
 * decision someone makes, not something an update does to them overnight.
 *
 * Why it replays the purge instead of writing its own
 * ---------------------------------------------------
 * There is no second list of "orphan cleanup" statements. Discovery derives its
 * targets from {@see Space_Purge::relations()}, and remediation fires
 * `jetonomy_purge_orphan_space`, which {@see Space_Purge} and Pro already
 * listen to. The backfill IS the forward fix, replayed against the spaces it
 * never got to run for. A table added to the relations map tomorrow is swept by
 * this code with no edit here.
 *
 * Scale
 * -----
 * Discovery walks SPACES, not rows. A 500k-row `jt_posts` still only references
 * as many distinct `space_id` values as the site has ever had spaces, so this
 * is a `DISTINCT` walk of an indexed column plus one PK lookup per value —
 * bounded by space count, not row count. The purge then runs one space at a
 * time under a wall-clock budget, draining a durable queue a slice at a time.
 */
final class Space_Backfill {

	/** Durable queue of orphan space ids still to purge across run_batch() calls. */
	private const QUEUE_OPTION = 'jetonomy_orphan_space_purge_queue';

	/**
	 * Max orphan ids discovered per pass.
	 *
	 * Draining the queue re-runs discovery, so a site with more orphans than
	 * this simply takes more passes — the sweep is still complete.
	 */
	private const DISCOVERY_CAP = 5000;

	/**
	 * Relations that can hold a SPACE id, derived from the live purge map.
	 *
	 * Only `ref => 'space'` entries are discovery targets: a post- or
	 * reply-referencing row cannot tell us which space it belonged to, and it
	 * does not need to — purging the space that owns those topics removes them
	 * through the same map.
	 *
	 * The space table's own row is excluded; it is the thing we are checking
	 * existence against, so it can never be orphaned from itself.
	 *
	 * @return array<int,array{table:string,column:string,where:string}>
	 */
	public static function columns(): array {
		$out = [];
		foreach ( Space_Purge::relations() as $r ) {
			if ( 'space' !== $r['ref'] || $r['self'] ) {
				continue;
			}
			$out[] = [
				'table'  => $r['table'],
				'column' => $r['column'],
				'where'  => $r['where'],
			];
		}
		return $out;
	}

	/**
	 * Distinct space ids referenced by our tables that no longer exist in jt_spaces.
	 *
	 * `<> 0` guards the same way {@see Privacy_Backfill::find_orphans()} does:
	 * 0 is the plugin's "no space" sentinel rather than a real reference, and
	 * NULL is excluded by the same predicate (`NULL <> 0` is never true).
	 *
	 * @param int $limit Max ids to return.
	 * @return int[]
	 */
	public static function find_orphans( int $limit = self::DISCOVERY_CAP ): array {
		global $wpdb;

		$spaces = table( 'spaces' );
		if ( ! self::table_exists( $spaces ) ) {
			return [];
		}

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

			// LEFT JOIN ... IS NULL rather than NOT IN (SELECT id FROM spaces):
			// a PK lookup per distinct value, index-only on the scanned side.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT t.{$col} FROM {$tbl} t
					 LEFT JOIN {$spaces} s ON s.id = t.{$col}
					 WHERE t.{$col} <> 0 AND s.id IS NULL{$extra}
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
	 * Per-relation orphan row counts — the "what would this remove" report.
	 *
	 * Read-only. Backs `wp jetonomy space scan-orphans` and the CLI's post-run
	 * confirmation, so an owner can see the remediation happened rather than
	 * taking our word for it.
	 *
	 * @return array<int,array{table:string,column:string,orphan_rows:int}>
	 */
	public static function count_orphans(): array {
		global $wpdb;

		$spaces = table( 'spaces' );
		if ( ! self::table_exists( $spaces ) ) {
			return [];
		}

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
				 LEFT JOIN {$spaces} s ON s.id = t.{$col}
				 WHERE t.{$col} <> 0 AND s.id IS NULL{$extra}"
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
	 * it discoverable, so a second run finds nothing. An interrupted run leaves
	 * the rest queued. The queue is a durable option, not a transient — an
	 * object cache evicting it under memory pressure would silently abandon a
	 * half-finished cleanup.
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
		 * Seconds one orphan-space purge slice may spend before stopping at a
		 * space boundary. Mirrors `jetonomy_orphan_purge_batch_seconds`.
		 *
		 * @param float $seconds Default 15.
		 */
		$seconds  = (float) apply_filters( 'jetonomy_orphan_space_purge_batch_seconds', $seconds );
		$deadline = microtime( true ) + max( 1.0, $seconds );
		$purged   = 0;

		while ( $queue ) {
			$space_id = (int) array_shift( $queue );
			if ( $space_id > 0 ) {
				/**
				 * Purge every trace of a space id that no longer exists.
				 *
				 * {@see Space_Purge} listens, and Pro listens for its own
				 * tables, so this reuses the real purge path rather than
				 * reimplementing it.
				 *
				 * @param int $space_id The orphaned (already-deleted) space id.
				 */
				do_action( 'jetonomy_purge_orphan_space', $space_id );
				++$purged;
			}

			// Stop at a space boundary — never mid-space, so a killed request
			// can never leave one space half-purged.
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
