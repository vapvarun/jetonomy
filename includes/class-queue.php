<?php
/**
 * One place that decides how free schedules background work.
 *
 * Action Scheduler when it is loaded and its store is ready (it ships with
 * WooCommerce and many other plugins), WP-Cron otherwise. Every free class that
 * queues a job goes through here, so the "which scheduler, is it ready, did it
 * accept the job" rules live once. See docs/standards/background-jobs.md.
 *
 * Recurring jobs stay in Cron::ensure_scheduled(), the only place that arms them.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

final class Queue {

	/** Action Scheduler group for every free job. */
	public const GROUP = 'jetonomy';

	/** Days a finished job is kept by prune_history() (filter: jetonomy_finished_action_retention_days). */
	public const FINISHED_RETENTION_DAYS = 7;

	/** Finished jobs deleted per prune_history() call. */
	private const PRUNE_BATCH = 200;

	/**
	 * Whether Action Scheduler is loaded and may be called. Its functions exist
	 * from plugins_loaded, but its store is not ready before action_scheduler_init.
	 */
	public static function has_as(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * Queue a one-off job to run as soon as possible.
	 *
	 * @param string $hook          Action hook.
	 * @param array  $args          Hook arguments.
	 * @param bool   $cron_fallback Use WP-Cron when Action Scheduler is not available
	 *                              or refuses the job. False for work the caller would
	 *                              rather finish inline than leave to WP-Cron.
	 * @param int    $cron_delay    Seconds from now for the WP-Cron fallback.
	 * @return bool Whether a scheduler accepted the job.
	 */
	public static function async( string $hook, array $args = array(), bool $cron_fallback = true, int $cron_delay = 0 ): bool {
		// as_enqueue_async_action() answers 0 when the store refuses the job; that is not a hand-off.
		if ( self::has_as() && as_enqueue_async_action( $hook, $args, self::GROUP ) ) {
			return true;
		}
		if ( ! $cron_fallback ) {
			return false;
		}
		if ( wp_next_scheduled( $hook, $args ) ) {
			return true;
		}
		return false !== wp_schedule_single_event( time() + $cron_delay, $hook, $args );
	}

	/**
	 * Queue a one-off job unless an identical one (same hook and arguments) is
	 * already waiting. A copy that is running does not count: an event that
	 * arrives mid-run may have missed what that run already read, so it needs
	 * a run of its own.
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Hook arguments.
	 * @return bool Whether the job is queued.
	 */
	public static function async_once( string $hook, array $args = array() ): bool {
		return self::is_queued( $hook, $args, false ) || self::async( $hook, $args );
	}

	/**
	 * Run a job once at a given time.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $hook      Action hook.
	 * @param array  $args      Hook arguments.
	 * @return bool Whether a scheduler accepted the job.
	 */
	public static function at( int $timestamp, string $hook, array $args = array() ): bool {
		if ( self::has_as() ) {
			return (bool) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		}
		return false !== wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Whether a copy with exactly these arguments is pending (or, by default, running).
	 *
	 * @param string $hook            Action hook.
	 * @param array  $args            Hook arguments.
	 * @param bool   $include_running Whether an in-progress copy counts.
	 */
	public static function is_queued( string $hook, array $args = array(), bool $include_running = true ): bool {
		if ( self::has_as() ) {
			$found = $include_running
				? as_has_scheduled_action( $hook, $args, self::GROUP )
				: (bool) as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'args'     => $args,
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 1,
					),
					'ids'
				);
			if ( $found ) {
				return true;
			}
		}
		return false !== wp_next_scheduled( $hook, $args );
	}

	/**
	 * Next run time of an argument-less job, or false.
	 *
	 * @param string $hook Action hook.
	 * @return int|false
	 */
	public static function next( string $hook ) {
		if ( self::has_as() ) {
			$next = as_next_scheduled_action( $hook, array(), self::GROUP );
			if ( is_int( $next ) ) {
				return $next;
			}
		}
		return wp_next_scheduled( $hook );
	}

	/**
	 * Cancel every queued copy of a job, whatever its arguments, in both schedulers.
	 *
	 * Passing a group with empty arguments makes Action Scheduler match only
	 * argument-less copies, which is how per-member jobs used to survive a cancel.
	 *
	 * @param string $hook Action hook.
	 */
	public static function cancel( string $hook ): void {
		if ( self::has_as() ) {
			as_unschedule_all_actions( $hook );
		}
		wp_unschedule_hook( $hook );
	}

	/**
	 * Register a recurring job of the Jetonomy family. Free's Cron::ensure_scheduled() arms every registered job on
	 * cron, wp-admin and WP-CLI requests (never on a visitor's page view), so there is one scheduler for Free and
	 * Pro. Call it from boot, before action_scheduler_init.
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
	 * Delete this family's finished job history: completed actions in the 'jetonomy' group (Free and Pro) older than
	 * FINISHED_RETENTION_DAYS, with their log rows, at most PRUNE_BATCH per call. Action Scheduler's own cleaner keeps every plugin's history
	 * for 31 days, and our plugins must not change that site-wide setting, so we trim only our own rows. Failed and
	 * pending rows are never touched. Called from the daily activity prune, not a job of its own.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune_history(): int {
		if ( ! self::has_as() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		/**
		 * Days a finished (completed) Jetonomy job is kept before Jetonomy deletes it with its log rows. Only the
		 * 'jetonomy' group is touched; failed and pending jobs are never deleted.
		 *
		 * @since 2.0.8
		 *
		 * @param int $days Whole days, at least 1. Default 7.
		 */
		$days = max( 1, (int) apply_filters( 'jetonomy_finished_action_retention_days', self::FINISHED_RETENTION_DAYS ) );
		$ids  = as_get_scheduled_actions(
			array(
				'group'        => self::GROUP,
				'status'       => \ActionScheduler_Store::STATUS_COMPLETE,
				'date'         => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
				'date_compare' => '<',
				'per_page'     => self::PRUNE_BATCH,
				'orderby'      => 'none',
			),
			'ids'
		);
		$store = \ActionScheduler::store();
		foreach ( (array) $ids as $id ) {
			$store->delete_action( (int) $id ); // The DB logger deletes the action's log rows on this hook.
		}
		return count( (array) $ids );
	}
}
