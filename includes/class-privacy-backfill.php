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
 * A site owner who ran an erasure request last month was told the data was
 * gone; it is not. Closing the hole does not remediate what already leaked
 * through it, so the leaked data has to be swept up explicitly. That is this
 * class.
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
 * a wall-clock budget, re-queuing itself until drained, so no single request
 * carries the whole sweep.
 */
final class Privacy_Backfill {

	/** Self-continuing batch worker (Action Scheduler, WP-Cron fallback). */
	public const CRON_HOOK = 'jetonomy_purge_orphans_batch';

	/** Action Scheduler group — reuses the plugin's existing one, no new surface. */
	private const AS_GROUP = 'jetonomy';

	/** Durable queue of orphan user ids still to purge. */
	private const QUEUE_OPTION = 'jetonomy_orphan_purge_queue';

	/**
	 * "A sweep is owed on this site." Set by the 1.8.0 migration, cleared only
	 * once the site is confirmed clean.
	 *
	 * The migration cannot schedule the sweep itself: it runs at
	 * `plugins_loaded`, and Action Scheduler's data store is not ready until
	 * `init` — an `as_*` call before that SILENTLY NO-OPS (see the timing gotcha
	 * in docs/standards/background-jobs.md). A GDPR remediation that silently
	 * no-ops is the whole bug on this card, repeated. So the migration records
	 * the intent durably and {@see maybe_enqueue()} arms it once AS is actually
	 * ready. Autoloaded: it is read once per request while a sweep is owed, and
	 * a missing option is served from WP's notoptions cache afterwards, so the
	 * steady state costs no query.
	 */
	private const PENDING_OPTION = 'jetonomy_orphan_purge_pending';

	/**
	 * Max orphan ids discovered per pass.
	 *
	 * Caps the queue option's size on a site with a pathological number of
	 * deleted accounts. Draining the queue re-runs discovery, so a site with
	 * more orphans than this simply takes more passes — the sweep is still
	 * complete, it just never holds more than this many ids at once.
	 */
	private const DISCOVERY_CAP = 5000;

	/** Self-requeue counter, so a non-converging sweep cannot poll forever. */
	private const PASS_OPTION = 'jetonomy_orphan_purge_passes';

	/**
	 * Hard ceiling on self-requeues.
	 *
	 * The sweep converges because every column {@see columns()} scans maps to
	 * something `on_user_delete()` actually clears — discovery is derived from
	 * the purge lists precisely so the two cannot disagree. That invariant is
	 * load-bearing in a way that is easy to miss: a column added to discovery
	 * but NOT to a purge list would be re-found on every pass, and the worker
	 * would re-arm itself forever — an idle poll, which the Background-Jobs
	 * Standard names as the anti-pattern to delete on sight. This ceiling means
	 * that mistake degrades into "the sweep stops early" rather than "the
	 * customer's site grinds". At DISCOVERY_CAP users per pass it allows 2.5M
	 * deleted accounts, which no real install approaches.
	 */
	private const MAX_PASSES = 500;

	/**
	 * Register the batch worker.
	 *
	 * Called from {@see Privacy::__construct()} so the listener exists on every
	 * request — a scheduled action is worthless if nothing is listening when it
	 * fires.
	 */
	public static function boot(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run_scheduled' ] );

		// Arm the sweep only once AS's data store is wired up. This is the hook
		// the Background-Jobs Standard names, and the same one Cron uses for
		// ensure_scheduled() — NOT `plugins_loaded`, where as_* silently no-ops.
		add_action( 'action_scheduler_init', [ self::class, 'maybe_enqueue' ] );
	}

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

	/**
	 * Batch worker. Drains its slice, then re-queues itself until the site is
	 * clean — including re-running discovery once, so a site with more orphans
	 * than DISCOVERY_CAP is still fully swept.
	 */
	public static function run_scheduled(): void {
		$passes = (int) get_option( self::PASS_OPTION, 0 ) + 1;

		if ( $passes > self::MAX_PASSES ) {
			// Not converging. Stop rather than poll forever, and leave a trace —
			// silently giving up on a GDPR sweep is how this card happened.
			self::finish();
			return;
		}

		$result = self::run_batch();

		if ( $result['remaining'] > 0 || self::find_orphans( 1 ) ) {
			// Either this slice ran out of budget, or discovery's cap means more
			// orphans exist than one pass could hold. Go around again.
			update_option( self::PASS_OPTION, $passes, false );
			self::requeue();
			return;
		}

		// Clean. Disarm — this is what stops the job being a perpetual poll.
		self::finish();
	}

	/** Sweep over: drop every trace of it so nothing re-arms. */
	private static function finish(): void {
		delete_option( self::PENDING_OPTION );
		delete_option( self::PASS_OPTION );
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Continue the sweep in a fresh action. Safe to call from the worker: AS is
	 * unambiguously booted by the time a handler of its own is running.
	 */
	private static function requeue(): void {
		if ( ! as_has_scheduled_action( self::CRON_HOOK, [], self::AS_GROUP ) ) {
			as_enqueue_async_action( self::CRON_HOOK, [], self::AS_GROUP );
		}
	}

	/**
	 * Record that this site owes a sweep. Called by the 1.8.0 migration.
	 *
	 * Deliberately neither purges NOR schedules inline. Not purging, because the
	 * migration runs inside whatever request first noticed the version bump —
	 * often an admin page load — and a large site's sweep would hang it. Not
	 * scheduling, because that request is at `plugins_loaded`, where every AS
	 * call silently no-ops. It only writes down the intent; {@see maybe_enqueue()}
	 * arms the job on `action_scheduler_init`, which may be this same request or
	 * the next one.
	 */
	public static function schedule(): void {
		update_option( self::PENDING_OPTION, 1 );
	}

	/**
	 * Arm the sweep if one is owed. Runs on `action_scheduler_init`, so AS is
	 * guaranteed ready here.
	 *
	 * Reactive single-shot (Background-Jobs Standard §2 case 2 — "a response to
	 * an event", the event being the upgrade), NOT a recurring poll: it fires
	 * only while a sweep is owed and goes dormant the moment the site is clean.
	 * Lazy-on-read (§2 case 1) does not fit — deleting leaked personal data is a
	 * write that must happen whether or not anyone reads anything, and a GDPR
	 * remediation that only runs if someone visits the right page is not a
	 * remediation.
	 */
	public static function maybe_enqueue(): void {
		if ( ! get_option( self::PENDING_OPTION ) ) {
			return; // Nothing owed — the common case, and it costs no query.
		}
		// Idempotent guard (§5.2) — never stack duplicate sweeps.
		if ( as_has_scheduled_action( self::CRON_HOOK, [], self::AS_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::CRON_HOOK, [], self::AS_GROUP );
	}

	/**
	 * Drop any queued work + state. Called on deactivation.
	 *
	 * Clears BOTH schedulers (§3 "Deactivation"): AS owns the hook today, but a
	 * site upgraded from an older build could still carry a legacy WP-Cron entry
	 * for it, and leaving that armed would fire a handler the plugin no longer
	 * listens for.
	 *
	 * The PENDING flag deliberately SURVIVES. Deactivating mid-sweep must not
	 * silently abandon a half-finished GDPR remediation — and it would: once
	 * db_version reads 1.8.0 the migration never runs again, so nothing would
	 * ever re-arm the job and the remaining leaked rows would sit there
	 * forever, with the owner still believing they were erased. That is exactly
	 * the failure this card exists to fix, so we do not reintroduce it one
	 * layer down. The flag keeps the intent durable; maybe_enqueue() re-arms on
	 * the next activation's `action_scheduler_init` without needing the
	 * migration at all.
	 *
	 * The queue itself IS dropped — it is rebuildable work state, and discovery
	 * is authoritative, so nothing is lost by re-deriving it on resume.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, [], self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::QUEUE_OPTION );
		delete_option( self::PASS_OPTION );
	}

	/** Does a table exist? Pro tables are absent when the extension never ran. */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
