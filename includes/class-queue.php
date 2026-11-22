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
 * - at() and async_once() insert with Action Scheduler's unique flag, so two requests arriving together queue one copy.
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
	 * Tests only: replaces microtime( true ) in now(), so a time budget can be tested without waiting.
	 *
	 * @internal
	 * @var \Closure|null
	 */
	public static ?\Closure $clock = null;

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
	 * @param bool   $unique    Insert only when no pending or running copy with these arguments exists (one atomic
	 *                          insert, so requests arriving together add one copy). WP-Cron already drops a duplicate
	 *                          within ten minutes.
	 * @return bool Whether a scheduler accepted the job (false when $unique found a copy).
	 */
	public static function at( int $timestamp, string $hook, array $args = array(), bool $unique = false ): bool {
		if ( self::has_as() ) {
			return (bool) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, $unique );
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
		return (int) wp_next_scheduled( $hook );
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
	 * - jetonomy_pro_upgrade_jobs: converts the old version's queued jobs; the version is already stored, so nothing
	 *   would queue it again and old rows (with their secrets) would stay.
	 *
	 * Limit: while the plugin stays off, Action Scheduler (loaded by another plugin) runs these rows with no
	 * listener and marks them complete after its next run, about a minute; the update flow re-activates first.
	 * Member erasure and moderation cascades run in the request (no job), so none is listed.
	 */
	public const KEEP_ON_DEACTIVATE = array(
		'jetonomy_space_purge_batch',
		'jetonomy_publish_scheduled',
		'jetonomy_fanout_post_subscribers',
		'jetonomy_fanout_reply_subscribers',
		'jetonomy_pro_announce_post',
		'jetonomy_pro_upgrade_jobs',
	);

	/**
	 * Deactivation's cancel: cancel each hook unless it is in KEEP_ON_DEACTIVATE.
	 *
	 * @since 2.0.8
	 *
	 * @param string[] $hooks Hooks of the deactivated plugin or extension.
	 */
	public static function cancel_on_deactivate( array $hooks ): void {
		foreach ( array_diff( $hooks, self::KEEP_ON_DEACTIVATE ) as $hook ) {
			self::cancel( $hook );
		}
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
				'status'            => $status,
				$age_by             => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
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
