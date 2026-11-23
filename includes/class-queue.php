<?php
/**
 * The one place Jetonomy (Free and Pro) queues background work.
 *
 * Action Scheduler (bundled in libs/action-scheduler) when its store is ready, WP-Cron before that, for example when
 * an upgrade routine queues work early in a request. Every Free and Pro class that queues, checks or cancels a job
 * goes through here, so the group, the "is the store ready" rule and the clean-up of our own finished rows live once.
 * Rules and the job register: docs/standards/background-jobs.md.
 *
 * Lookups (is_queued, is_pending, cancel, pending_args) match by hook and arguments in any group: every Jetonomy hook
 * name starts with jetonomy_ and is ours alone.
 *
 * Job arguments are positional. Action Scheduler passes them with array_values(); the WP-Cron branches do the same,
 * because WP-Cron would pass string keys to the callback as named parameters on PHP 8.
 *
 * Differences from MediaVerse's \WPMediaVerse\Core\Jobs (the sister plugin's entry point; the shared method names
 * behave the same):
 * - ensure_recurring( $hook, $first, $interval ) takes only an interval; MediaVerse also takes a cron expression.
 * - recurring() is a registry (filter jetonomy_recurring_jobs) that Cron::ensure_scheduled() arms, on cron, wp-admin
 *   and WP-CLI requests and on Action Scheduler's daily action_scheduler_ensure_recurring_actions; each MediaVerse job
 *   arms itself on that action.
 * - delete_rows() deletes every row of a retired hook (the 2.0.7 webhook rows held URLs and secrets); MediaVerse has
 *   none and deletes by hook list in uninstall.
 * - No adopt_pending() or LEGACY_GROUPS: Jetonomy never used another group.
 * - at( ..., $unique ) and async_once() insert with Action Scheduler's unique flag, so two requests arriving together
 *   queue one copy; at() adds a plain copy when only a RUNNING copy blocked the insert, so a job re-arms from its run.
 * - Deactivation: cancel_on_deactivate( $hooks ) cancels each hook not in KEEP_ON_DEACTIVATE (MediaVerse:
 *   cancel_pending( $hooks ), set-based, returns a count).
 * - Recovery of kept jobs failed while the plugin was off: flag_recovery() + recover_kept_jobs(), RECOVER_BATCH,
 *   RECOVER_WINDOW_DAYS (MediaVerse: requeue_after_activation( $transient, $hooks ) + requeue_unheard( $hooks ),
 *   REQUEUE_BATCH, REQUEUE_DAYS). Same guard order (scheduling request, store, then the flag); Jetonomy also skips
 *   hooks with no listener and catches per row.
 * - prune_history() ages completed and failed rows by last attempt, cancelled rows by scheduled date (Action Scheduler
 *   records no cancel time).
 * - TIME_BUDGET is public (tests and Pro read it); private in MediaVerse.
 * - cancel( $hook, array() ) cancels every copy, but is_queued( $hook, array() ) asks for the argument-less copy only
 *   (pass null for any arguments). next() answers 0 both when nothing is queued and when a copy is running.
 *
 * @package Jetonomy
 * @since   2.0.8
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

final class Queue {

	/** Action Scheduler group for every Jetonomy job, Free and Pro. */
	public const GROUP = 'jetonomy';

	/** Days a finished job is kept by prune_history() (filter: jetonomy_finished_action_retention_days). */
	public const FINISHED_RETENTION_DAYS = 7;

	/** Days a failed job is kept for diagnosis: Action Scheduler 4.0's own default for failed rows. */
	public const FAILED_RETENTION_DAYS = 90;

	/** Finished jobs deleted per batch. */
	private const PRUNE_BATCH = 200;

	/** Batches per status per prune_history() call: at most 10,000 rows a day each; the rest goes on the next run. */
	private const PRUNE_MAX_BATCHES = 50;

	/** Seconds one prune_history() or delete_rows() call may spend deleting; the rest goes on the next run. */
	public const TIME_BUDGET = 10;

	/**
	 * Replaces microtime( true ) in now(); set only through set_clock().
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $clock = null;

	/** Pending actions pending_args() reads per hook. */
	public const PENDING_READ_LIMIT = 100;

	/**
	 * Whether Action Scheduler may be called: its functions exist from plugins_loaded, its store only from
	 * action_scheduler_init.
	 *
	 * @since 2.0.8
	 */
	public static function has_as(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * The clock every Jetonomy time budget reads (microtime( true ) unless a test set $clock).
	 *
	 * @since 2.0.8
	 */
	public static function now(): float {
		return null === self::$clock ? microtime( true ) : (float) ( self::$clock )();
	}

	/**
	 * Tests only: a fake clock for now(), so a time budget can be tested without waiting. Null restores the real one.
	 *
	 * @internal
	 * @since 2.0.8
	 *
	 * @param \Closure|null $clock Returns the time as a float.
	 */
	public static function set_clock( ?\Closure $clock ): void {
		self::$clock = $clock;
	}

	/**
	 * Run a callback now if Action Scheduler's store is ready, else on action_scheduler_init. Upgrade routines and
	 * early writes run before the store loads, and queuing before then fails.
	 *
	 * @since 2.0.8
	 *
	 * @param callable $callback Callback (no arguments).
	 */
	public static function when_ready( callable $callback ): void {
		if ( did_action( 'action_scheduler_init' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			$callback();
		} else {
			add_action( 'action_scheduler_init', static fn() => $callback() );
		}
	}

	/**
	 * Queue a one-off job to run as soon as possible.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook          Action hook.
	 * @param array  $args          Hook arguments (positional).
	 * @param bool   $cron_fallback Use WP-Cron when Action Scheduler is not available or refuses the job. False for
	 *                              work the caller would rather finish inline than leave to WP-Cron.
	 * @param int    $cron_delay    Seconds from now for the WP-Cron fallback.
	 * @return bool Whether a scheduler accepted the job; false means the caller should do the work now.
	 */
	public static function async( string $hook, array $args = array(), bool $cron_fallback = true, int $cron_delay = 0 ): bool {
		// as_enqueue_async_action() answers 0 when the store refuses the job; that is not a hand-off.
		if ( self::has_as() && as_enqueue_async_action( $hook, $args, self::GROUP ) ) {
			return true;
		}
		if ( ! $cron_fallback ) {
			return false;
		}
		$args = array_values( $args );
		if ( wp_next_scheduled( $hook, $args ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + $cron_delay, $hook, $args, true );
	}

	/**
	 * Queue a job unless a copy with the same arguments is already waiting to start. A copy that is running now does
	 * not count: it may have read its data before the change that queues this one, so a chain may queue its next pass.
	 * Once Action Scheduler is ready only its own pending copies count: a WP-Cron fallback left by an early request is
	 * not a reason to skip the Action Scheduler job. The insert uses Action Scheduler's unique flag, so two requests
	 * arriving together queue one copy; when it is refused because a copy is running, a plain copy is queued.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Hook arguments.
	 * @return bool Whether a copy is waiting now (queued here or already).
	 */
	public static function async_once( string $hook, array $args = array() ): bool {
		if ( ! self::has_as() ) {
			return self::is_pending( $hook, $args ) || self::async( $hook, $args );
		}
		// ponytail: a request racing a running copy can still add a second pending copy; it is harmless (one extra pass).
		return as_enqueue_async_action( $hook, $args, self::GROUP, true ) || self::as_pending( $hook, $args ) || self::async( $hook, $args );
	}

	/**
	 * Run a job once at a given time.
	 *
	 * @since 2.0.8
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $hook      Action hook.
	 * @param array  $args      Hook arguments.
	 * @param bool   $unique    Insert only when no pending copy with these arguments exists (one atomic insert, so
	 *                          requests arriving together add one copy). A running copy does not count, so a job may
	 *                          re-arm itself from inside its run. WP-Cron already drops a duplicate within ten minutes.
	 * @return bool Whether a scheduler accepted the job (false when $unique found a pending copy).
	 */
	public static function at( int $timestamp, string $hook, array $args = array(), bool $unique = false ): bool {
		if ( self::has_as() ) {
			if ( as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, $unique ) ) {
				return true;
			}
			// Action Scheduler's unique insert also refuses while a copy RUNS, so a job re-arming itself from inside its
			// own run would get nothing. Only a pending copy means the later run is already coming.
			return $unique && ! self::as_pending( $hook, $args ) && (bool) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		}
		return true === wp_schedule_single_event( $timestamp, $hook, array_values( $args ), true );
	}

	/**
	 * Whether a pending or running copy exists, in either scheduler. Use it to avoid starting the same work twice; use
	 * is_pending() when the question is whether a later run is still coming.
	 *
	 * @since 2.0.8
	 *
	 * @param string     $hook            Action hook.
	 * @param array|null $args            Exact arguments, or null for any.
	 * @param bool       $include_running Deprecated (2.0.7 form): false asks is_pending() instead.
	 */
	public static function is_queued( string $hook, ?array $args = null, bool $include_running = true ): bool {
		if ( ! $include_running ) {
			_deprecated_argument( __METHOD__, '2.0.8', 'Use \\Jetonomy\\Queue::is_pending() to ask whether a copy is waiting.' );
			return self::is_pending( $hook, $args );
		}
		if ( self::has_as() && as_has_scheduled_action( $hook, $args ) ) {
			return true;
		}
		return null === $args ? self::cron_has( $hook ) : false !== wp_next_scheduled( $hook, array_values( $args ) );
	}

	/**
	 * Whether a copy is waiting to start, in either scheduler. A running copy does not count.
	 *
	 * @since 2.0.8
	 *
	 * @param string     $hook Action hook.
	 * @param array|null $args Exact arguments, or null for any.
	 */
	public static function is_pending( string $hook, ?array $args = null ): bool {
		if ( self::has_as() && self::as_pending( $hook, $args ) ) {
			return true;
		}
		return null === $args ? self::cron_has( $hook ) : false !== wp_next_scheduled( $hook, array_values( $args ) );
	}

	/**
	 * Whether Action Scheduler holds a pending copy. Call only when has_as().
	 *
	 * @param string     $hook Action hook.
	 * @param array|null $args Exact arguments, or null for any.
	 */
	private static function as_pending( string $hook, ?array $args ): bool {
		$query = array(
			'hook'     => $hook,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		);
		if ( null !== $args ) {
			$query['args'] = $args;
		}
		return (bool) as_get_scheduled_actions( $query, 'ids' );
	}

	/**
	 * Next run time of a pending copy with any arguments.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook Action hook.
	 * @return int Unix time; 0 when none is pending, one is running now, or the soonest is an async copy (use
	 *             is_pending() to ask whether any copy waits).
	 */
	public static function next( string $hook ): int {
		if ( self::has_as() ) {
			$next = as_next_scheduled_action( $hook );
			if ( is_int( $next ) ) {
				return $next;
			}
		}
		// WP-Cron: wp_next_scheduled() matches one exact args list, so scan for the soonest copy with any args.
		foreach ( (array) _get_cron_array() as $time => $events ) { // Sorted by time.
			if ( isset( $events[ $hook ] ) ) {
				return (int) $time;
			}
		}
		return 0;
	}

	/**
	 * Jobs deactivation leaves pending, for Free and Pro. Deactivating a plugin is step one of the routine update
	 * (deactivate, replace files, activate), so it cancels only work that is recomputed or lost by nature: recurring
	 * and due-time jobs, digests, trust and badge sweeps, AI review, push and webhook sends, the lock sweep, re-index,
	 * recount and the mail poll. These jobs finish something a member or the owner already did, and nothing queues
	 * them again:
	 *
	 * - jetonomy_space_purge_batch: deletes the rows of a space the owner deleted (the space row is already gone).
	 * - jetonomy_publish_scheduled: publishes topics their authors scheduled.
	 * - jetonomy_fanout_post_subscribers / jetonomy_fanout_reply_subscribers: writes the in-app notifications for a
	 *   topic or reply already published; push and email are delivery and may be dropped, the in-app record may not.
	 * - jetonomy_pro_announce_post: notifies members of an announcement the owner published.
	 * - jetonomy_pro_upgrade_jobs: converts the old version's queued jobs; once the version is stored nothing queues it
	 *   again, and old rows (with their secrets) would stay.
	 * - jetonomy_pro_reindex_field / jetonomy_pro_backfill_searchable_fields: re-index custom fields for search; the
	 *   cursor (or id list) is in the args and nothing else queues the rest, so search would stay half-indexed.
	 * - jetonomy_pro_attachments_lock: locks the files of a space made private, page by page; cancelled midway, files
	 *   of a private space stay reachable by URL.
	 * - jetonomy_pro_deliver_webhook: the event already happened and its body lives only in the job args.
	 *
	 * While the plugin is off, Action Scheduler loaded by another plugin runs these rows with no listener and marks
	 * them FAILED ("no callbacks are registered") on its next run, about a minute; recover_kept_jobs() queues them
	 * again after the next activation or version change. A manual update deactivates and re-activates; core's
	 * one-click update deactivates silently (no hooks), so the version-change paths set the recovery flag too.
	 * Member erasure and moderation cascades run in the request (no job), so none is listed.
	 */
	public const KEEP_ON_DEACTIVATE = array(
		'jetonomy_space_purge_batch',
		'jetonomy_publish_scheduled',
		'jetonomy_fanout_post_subscribers',
		'jetonomy_fanout_reply_subscribers',
		'jetonomy_pro_announce_post',
		'jetonomy_pro_upgrade_jobs',
		'jetonomy_pro_reindex_field',
		'jetonomy_pro_backfill_searchable_fields',
		'jetonomy_pro_attachments_lock',
		'jetonomy_pro_deliver_webhook',
	);

	/** Transient set on activation: recover kept jobs that failed while the plugin was off. */
	public const RECOVER_FLAG = 'jetonomy_recover_kept_jobs';

	/** Failed rows recovered per scheduling request; the flag stays until a pass finds fewer. */
	public const RECOVER_BATCH = 200;

	/** Only kept rows that failed within this many days are re-queued. */
	public const RECOVER_WINDOW_DAYS = 7;

	/**
	 * Called by Free's and Pro's activation: the next scheduling request runs recover_kept_jobs().
	 *
	 * @since 2.0.8
	 */
	public static function flag_recovery(): void {
		set_transient( self::RECOVER_FLAG, 1, WEEK_IN_SECONDS );
	}

	/**
	 * Put back kept jobs (KEEP_ON_DEACTIVATE) that another plugin's Action Scheduler ran while ours was off: with no
	 * listener they failed with "no callbacks are registered". Only rows whose log says so are re-queued, so a job
	 * that really ran and errored stays failed. Each row is claimed first (failed to cancelled in one UPDATE), so of
	 * several parallel requests only one queues its copy (async_once, same args); the row is then deleted with its
	 * logs, or put back to failed when no scheduler takes the copy (the flag then stays set). Runs on scheduling requests while the activation flag is set, RECOVER_BATCH rows at a
	 * time, and only for hooks that have a listener now (Pro's are skipped while Pro is off). Only rows that failed
	 * within RECOVER_WINDOW_DAYS are replayed, so an old fan-out never reaches members long after the fact. The log
	 * message is matched in English, the site's language and the request's language; a row logged in a fourth
	 * language is missed. A row whose args no longer decode is left failed. The cheap checks come first: on a
	 * normal page view this returns before any query.
	 *
	 * @since 2.0.8
	 *
	 * @return int Rows re-queued.
	 */
	public static function recover_kept_jobs(): int {
		if ( ! self::is_scheduling_request() || ! self::has_as() || ! get_transient( self::RECOVER_FLAG ) ) {
			return 0;
		}
		$hooks = array_values( array_filter( self::KEEP_ON_DEACTIVATE, 'has_action' ) );
		if ( ! $hooks ) {
			return 0;
		}
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		// Action Scheduler writes the message in the runner's language: match English, this request's and the site's.
		$english = '%' . $wpdb->esc_like( 'will not be executed as no callbacks are registered' ) . '%';
		$like    = static function () use ( $wpdb, $english ): string {
			/* translators: Action Scheduler's own string, matched as written in its log. */
			$frag = trim( (string) preg_replace( '/^.*%1\$s/', '', __( 'Scheduled action for %1$s will not be executed as no callbacks are registered.', 'action-scheduler' ) ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			return mb_strlen( $frag ) >= 10 ? '%' . $wpdb->esc_like( $frag ) . '%' : $english; // Too short to match on its own.
		};
		$local   = $like();
		$site    = $local;
		if ( determine_locale() !== get_locale() && switch_to_locale( get_locale() ) ) {
			$site = $like();
			restore_previous_locale();
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - self::RECOVER_WINDOW_DAYS * DAY_IN_SECONDS );
		$in    = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Action Scheduler's API cannot filter by log message.
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT a.action_id FROM {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id
					WHERE g.slug = %s AND a.status = 'failed' AND a.hook IN ({$in}) AND a.last_attempt_gmt > %s
					AND EXISTS ( SELECT 1 FROM {$logs} l WHERE l.action_id = a.action_id AND ( l.message LIKE %s OR l.message LIKE %s OR l.message LIKE %s ) )
					ORDER BY a.action_id LIMIT %d",
					array_merge( array( self::GROUP ), $hooks, array( $since, $english, $local, $site, self::RECOVER_BATCH ) )
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$store    = \ActionScheduler::store();
		$done     = 0;
		$released = false;
		$claim    = static fn( int $id, string $from, string $to ): bool => 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$actions} SET status = %s WHERE action_id = %d AND status = %s", $to, $id, $from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- compare-and-set; Action Scheduler has none.
		foreach ( $ids as $id ) {
			// Claim first: only the request that turns the failed row into a cancelled one queues the copy. Without it,
			// a runner could finish a copy before a slower request reached the same row and queued it again
			// (async_once only blocks a duplicate while the first copy is pending or running). A row another request
			// holds counts as handled. ponytail: a request that dies between the claim and the copy leaves the row
			// cancelled and the job lost; prune_history() deletes the row (cancelled rows age by scheduled date).
			if ( ! $claim( $id, 'failed', 'canceled' ) ) {
				continue;
			}
			try {
				$action = $store->fetch_action( (string) $id );
				if ( '' === $action->get_hook() ) {
					$claim( $id, 'canceled', 'failed' ); // Args that no longer decode (a NullAction): left failed for the owner.
					continue;
				}
				if ( ! self::async_once( $action->get_hook(), (array) $action->get_args() ) ) {
					$claim( $id, 'canceled', 'failed' ); // No scheduler took the copy: give the row back for the next pass.
					$released = true;
					continue;
				}
				++$done;
				$store->delete_action( (string) $id ); // The DB logger deletes the row's logs on this hook.
			} catch ( \Throwable $e ) {
				unset( $e ); // Deleted by another process meanwhile: the copy, if any, is queued either way.
			}
		}
		if ( count( $ids ) < self::RECOVER_BATCH && ! $released ) {
			delete_transient( self::RECOVER_FLAG );
		}
		return $done;
	}

	/**
	 * Deactivation's cancel: cancel each hook unless it is in KEEP_ON_DEACTIVATE, in both schedulers, set-based.
	 *
	 * @since 2.0.8
	 *
	 * @param string[] $hooks Hooks of the deactivated plugin or extension.
	 * @return int Action Scheduler rows cancelled.
	 */
	public static function cancel_on_deactivate( array $hooks ): int {
		$hooks = array_values( array_unique( array_diff( $hooks, self::KEEP_ON_DEACTIVATE ) ) );
		foreach ( $hooks as $hook ) {
			wp_unschedule_hook( $hook ); // WP-Cron copies (fallback or pre-2.0 installs).
		}
		return self::cancel_pending_rows( $hooks );
	}

	/** Pending rows one cancel_pending_rows() statement changes. */
	public const CANCEL_BATCH = 10000;

	/**
	 * Cancel every pending Action Scheduler row of these exact hooks in our group, set-based: one UPDATE of at most
	 * CANCEL_BATCH rows per statement, repeated until fewer change. Deactivation with 100,000 pending rows took
	 * minutes row by row. Pending only, never another group's rows; Action Scheduler's per-row cancel hook does not
	 * fire (nothing of ours listens to it). Needs no store, so it works during deactivation however early.
	 *
	 * @since 2.0.8
	 *
	 * @param string[] $hooks Exact hook names.
	 * @return int Rows cancelled.
	 */
	public static function cancel_pending_rows( array $hooks ): int {
		global $wpdb;
		$hooks = array_values( array_filter( array_map( 'strval', $hooks ) ) );
		if ( ! $hooks ) {
			return 0;
		}
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- set-based cancel; Action Scheduler's API cancels row by row.
		$group = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$groups} WHERE slug = %s", self::GROUP ) );
		if ( ! $group ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );
		$sql   = $wpdb->prepare( "UPDATE {$actions} SET status = 'canceled' WHERE status = 'pending' AND group_id = %d AND hook IN ({$in}) LIMIT %d", array_merge( array( $group ), $hooks, array( self::CANCEL_BATCH ) ) );
		$total = 0;
		do {
			$done   = (int) $wpdb->query( $sql );
			$total += max( 0, $done );
		} while ( $done >= self::CANCEL_BATCH );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $total;
	}

	/**
	 * Cancel pending copies of a job in both schedulers.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Exact arguments; empty cancels every copy whatever its arguments.
	 */
	public static function cancel( string $hook, array $args = array() ): void {
		if ( self::has_as() ) {
			as_unschedule_all_actions( $hook, $args );
		}
		if ( $args ) {
			wp_clear_scheduled_hook( $hook, array_values( $args ) );
		} else {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Arguments of the pending Action Scheduler copies of a job, oldest first, as stored.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook Action hook.
	 * @return array[] Up to PENDING_READ_LIMIT argument lists.
	 */
	public static function pending_args( string $hook ): array {
		if ( ! self::has_as() ) {
			return array();
		}
		$actions = (array) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => self::PENDING_READ_LIMIT,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);
		return array_values( array_map( static fn( $action ) => (array) $action->get_args(), $actions ) );
	}

	/**
	 * Register a recurring job of the Jetonomy family. Cron::ensure_scheduled() arms every registered job through
	 * ensure_recurring() on cron, wp-admin and WP-CLI requests (never on a visitor's page view), so there is one
	 * scheduler for Free and Pro. Call it from boot, before action_scheduler_init.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook        Action hook (takes no arguments).
	 * @param int    $interval    Seconds between runs.
	 * @param int    $first_delay Seconds from now to the first run (0 = now).
	 */
	public static function recurring( string $hook, int $interval, int $first_delay = 0 ): void {
		add_filter(
			'jetonomy_recurring_jobs',
			static function ( array $jobs ) use ( $hook, $interval, $first_delay ): array {
				$jobs[ $hook ] = array( $interval, $first_delay );
				return $jobs;
			}
		);
	}

	/**
	 * Make sure exactly one pending recurring row exists for an argument-less hook.
	 *
	 * "Is it scheduled? If not, schedule it" is not atomic, so requests arriving together each scheduled one; Action
	 * Scheduler's $unique flag inserts only when no pending copy exists, and extra copies an older version left behind
	 * are cancelled here. Only the argument-less row counts. Runs only on a scheduling request.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook     Action hook.
	 * @param int    $first    Unix time of the first run.
	 * @param int    $interval Seconds between runs.
	 */
	public static function ensure_recurring( string $hook, int $first, int $interval ): void {
		if ( ! self::is_scheduling_request() || ! self::has_as() ) {
			return;
		}
		$pending = count(
			(array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => array(),
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);
		if ( $pending ) {
			for ( $i = 1; $i < $pending; $i++ ) {
				as_unschedule_action( $hook, array() );
			}
			return;
		}
		as_schedule_recurring_action( $first, max( MINUTE_IN_SECONDS, $interval ), $hook, array(), self::GROUP, true );
	}

	/**
	 * Whether this request may do scheduling and upkeep checks: cron, WP-CLI, an admin page load (not an admin-ajax
	 * call, which visitors' pages make too), or Action Scheduler's daily action_scheduler_ensure_recurring_actions run
	 * (Cron hooks ensure_scheduled() there, so a recurring row lost on a site nobody administers comes back within a
	 * day). Each check costs a query, so a visitor's request never pays for it.
	 *
	 * @since 2.0.8
	 */
	public static function is_scheduling_request(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( is_admin() && ! wp_doing_ajax() )
			|| doing_action( 'action_scheduler_ensure_recurring_actions' );
	}

	/**
	 * Delete this family's own job history in GROUP (Free and Pro), with the log rows: completed and cancelled actions
	 * older than FINISHED_RETENTION_DAYS and failed actions older than FAILED_RETENTION_DAYS. Action Scheduler's own cleaner keeps
	 * every plugin's history for a month (3.x never deletes failed rows) and that site-wide setting is not ours to
	 * change, so we trim only our own rows. Pending and running rows are never touched. A completed or failed row's age
	 * is counted from its last attempt (when it finished), not its scheduled time; a cancelled row never ran, so Action
	 * Scheduler records no such time for it, and its scheduled time is used. Runs on the daily activity prune, not a job of its own,
	 * for at most TIME_BUDGET seconds and PRUNE_MAX_BATCHES batches per status; the rest goes on the next run.
	 *
	 * @since 2.0.8
	 *
	 * @return int Rows deleted.
	 */
	public static function prune_history(): int {
		if ( ! self::has_as() ) {
			return 0;
		}
		/**
		 * Days a completed or cancelled Jetonomy job is kept before Jetonomy deletes it with its log rows. Only the
		 * 'jetonomy' group is touched; pending and running jobs are never deleted, failed ones after 90 days.
		 *
		 * @since 2.0.8
		 *
		 * @param int $days Whole days, at least 1. Default 7.
		 */
		$days     = max( 1, (int) apply_filters( 'jetonomy_finished_action_retention_days', self::FINISHED_RETENTION_DAYS ) );
		$deadline = self::now() + self::TIME_BUDGET;
		return self::delete_finished( \ActionScheduler_Store::STATUS_COMPLETE, $days, $deadline, 'modified' )
			+ self::delete_finished( \ActionScheduler_Store::STATUS_CANCELED, $days, $deadline, 'date' )
			+ self::delete_finished( \ActionScheduler_Store::STATUS_FAILED, self::FAILED_RETENTION_DAYS, $deadline, 'modified' );
	}

	/**
	 * Delete every row of one of our hooks in GROUP, whatever its status or date, with its log rows. For a retired hook
	 * whose stored arguments must not outlive the upgrade (a 2.0.7 webhook delivery carried its URL and secret). Stops
	 * after TIME_BUDGET seconds or PRUNE_MAX_BATCHES batches: a caller that got a non-zero count calls again (from a
	 * job of its own, never a visitor's request) until it gets 0.
	 *
	 * @since 2.0.8
	 *
	 * @param string $hook Action hook.
	 * @return int Rows deleted by this call.
	 */
	public static function delete_rows( string $hook ): int {
		if ( ! self::has_as() ) {
			return 0;
		}
		return self::delete_where(
			array(
				'hook'   => $hook,
				'status' => '',
			),
			self::now() + self::TIME_BUDGET
		);
	}

	/**
	 * Delete GROUP's actions of one finished status older than $days.
	 *
	 * @param string $status   Action Scheduler status.
	 * @param int    $days     Age in days.
	 * @param float  $deadline now() after which no further row is deleted.
	 * @param string $age_by   'modified' (last attempt: when it finished) or 'date' (scheduled time).
	 * @return int Rows deleted.
	 */
	private static function delete_finished( string $status, int $days, float $deadline, string $age_by ): int {
		return self::delete_where(
			array(
				'status'             => $status,
				$age_by              => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
				$age_by . '_compare' => '<',
			),
			$deadline
		);
	}

	/**
	 * Delete GROUP's actions matching a query, with their log rows, PRUNE_BATCH at a time, at most PRUNE_MAX_BATCHES,
	 * until $deadline. A row another process deleted first (Action Scheduler's own cleaner) is skipped, not fatal.
	 *
	 * @param array $query    as_get_scheduled_actions() arguments; group, page size and order are set here.
	 * @param float $deadline now() after which no further row is deleted.
	 * @return int Rows deleted.
	 */
	private static function delete_where( array $query, float $deadline ): int {
		$store   = \ActionScheduler::store();
		$deleted = 0;
		$query   = array_merge(
			$query,
			array(
				'group'    => self::GROUP,
				'per_page' => self::PRUNE_BATCH,
				'orderby'  => 'none',
			)
		);
		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
			$ids = (array) as_get_scheduled_actions( $query, 'ids' );
			foreach ( $ids as $id ) {
				if ( self::now() >= $deadline ) {
					return $deleted;
				}
				try {
					$store->delete_action( (int) $id ); // The DB logger deletes the action's log rows on this hook.
					++$deleted;
				} catch ( \InvalidArgumentException $e ) {
					unset( $e ); // Already gone: deleted by another process between the read and this delete.
				}
			}
			if ( count( $ids ) < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $deleted;
	}

	/**
	 * Whether WP-Cron holds an event for a hook with any arguments.
	 *
	 * @param string $hook Action hook.
	 */
	private static function cron_has( string $hook ): bool {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				return true;
			}
		}
		return false;
	}
}
