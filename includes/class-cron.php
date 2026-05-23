<?php
/**
 * Scheduled tasks (cron).
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Notifications\Verification_Reminder;
use Jetonomy\Trust\Trust_Evaluator;
use function Jetonomy\table;

class Cron {

	private const AS_GROUP = 'jetonomy';

	/**
	 * Recurring actions Jetonomy schedules. hook => interval (seconds).
	 *
	 * Run via Action Scheduler so background work doesn't drop on quiet sites
	 * (WP-Cron only fires on page views — bad for trust evaluation, ban expiry,
	 * activity pruning, scheduled-post publishing at the scale targets).
	 */
	private const RECURRING = [
		'jetonomy_trust_evaluation'      => 12 * HOUR_IN_SECONDS,
		'jetonomy_cleanup_expired'       => HOUR_IN_SECONDS,
		'jetonomy_prune_activity'        => DAY_IN_SECONDS,
		'jetonomy_cleanup_notifications' => WEEK_IN_SECONDS,
		'jetonomy_publish_scheduled'     => HOUR_IN_SECONDS,
		'jetonomy_verification_reminder' => HOUR_IN_SECONDS,
	];

	public function __construct() {
		// Handler listeners — fire whether scheduled via AS or (legacy) WP-Cron.
		add_action( 'jetonomy_trust_evaluation', [ $this, 'evaluate_trust_levels' ] );
		add_action( 'jetonomy_cleanup_expired', [ $this, 'cleanup_expired_restrictions' ] );
		add_action( 'jetonomy_prune_activity', [ $this, 'prune_activity_log' ] );
		add_action( 'jetonomy_cleanup_notifications', [ $this, 'cleanup_old_notifications' ] );
		add_action( 'jetonomy_publish_scheduled', [ $this, 'publish_scheduled_posts' ] );
		add_action( 'jetonomy_verification_reminder', [ Verification_Reminder::class, 'run' ] );

		// Self-heal: ensure every recurring action is registered. We hook the
		// 'action_scheduler_init' action AS fires once its data store is ready
		// (not 'init', which can run before AS's data store is wired up and
		// would emit "called incorrectly" notices on every page load).
		add_action( 'action_scheduler_init', [ self::class, 'ensure_scheduled' ] );
	}

	/**
	 * Schedule all cron events on plugin activation.
	 *
	 * During activation the plugins_loaded hook hasn't fired, so Action Scheduler
	 * may not yet have declared its global functions. ensure_scheduled() guards
	 * for that — anything not scheduled here will be picked up on the next init.
	 */
	public static function schedule(): void {
		self::ensure_scheduled();
	}

	/**
	 * Unschedule all cron events on plugin deactivation.
	 *
	 * Clears both AS-scheduled actions (current) and any legacy WP-Cron entries
	 * (idempotent — safe to call regardless of which scheduler the install was on).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array_keys( self::RECURRING ) as $hook ) {
				as_unschedule_all_actions( $hook, [], self::AS_GROUP );
			}
		}
		foreach ( array_keys( self::RECURRING ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Idempotent scheduler — ensures every recurring action exists in AS exactly
	 * once. On first run after upgrade from <1.4.4, also wipes the legacy WP-Cron
	 * registrations so the same handler doesn't fire from two schedulers.
	 */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return; // AS not booted yet (e.g. activation request before plugins_loaded). Retry next request.
		}

		// One-time migration: drop legacy WP-Cron registrations so AS owns these hooks.
		if ( ! get_option( 'jetonomy_cron_as_migrated' ) ) {
			foreach ( array_keys( self::RECURRING ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			update_option( 'jetonomy_cron_as_migrated', 1, false );
		}

		$now = time();
		foreach ( self::RECURRING as $hook => $interval ) {
			if ( as_has_scheduled_action( $hook, [], self::AS_GROUP ) ) {
				continue;
			}
			// Verification reminder starts +1h so it doesn't compete with everything else at time().
			$start = ( 'jetonomy_verification_reminder' === $hook ) ? $now + HOUR_IN_SECONDS : $now;
			as_schedule_recurring_action( $start, $interval, $hook, [], self::AS_GROUP );
		}
	}

	/**
	 * Evaluate and promote trust levels for all users (runs every 12h).
	 *
	 * Processes at most jetonomy_cron_batch_size profiles per run (default 500)
	 * to avoid hitting max_execution_time on large communities.
	 */
	public function evaluate_trust_levels(): void {
		global $wpdb;
		$profiles_t = table( 'user_profiles' );
		$replies_t  = table( 'replies' );
		$posts_t    = table( 'posts' );

		$batch = (int) apply_filters( 'jetonomy_cron_batch_size', 500, 'evaluate_trust_levels' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$profiles = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, post_count, reply_count, reputation, trust_level, created_at FROM {$profiles_t} WHERE trust_level < 4 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$batch
			)
		);

		if ( empty( $profiles ) ) {
			return;
		}

		$user_ids        = wp_list_pluck( $profiles, 'user_id' );
		$id_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$replies_received_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.author_id, COUNT(*) AS cnt
				 FROM {$replies_t} r
				 INNER JOIN {$posts_t} p ON r.post_id = p.id
				 WHERE p.author_id IN ({$id_placeholders})
				   AND r.author_id != p.author_id
				   AND r.status = 'publish'
				 GROUP BY p.author_id",
				...$user_ids
			)
		);

		$replies_received_map = [];
		foreach ( $replies_received_rows as $row ) {
			$replies_received_map[ (int) $row->author_id ] = (int) $row->cnt;
		}

		foreach ( $profiles as $profile ) {
			$days_active = $profile->created_at
				? (int) ( ( time() - strtotime( $profile->created_at ) ) / DAY_IN_SECONDS )
				: 0;

			$replies_received = $replies_received_map[ (int) $profile->user_id ] ?? 0;

			$stats = [
				'post_count'       => (int) $profile->post_count,
				'days_active'      => $days_active,
				'reputation'       => (int) $profile->reputation,
				'replies_received' => $replies_received,
			];

			$new_level = Trust_Evaluator::evaluate_level( $stats );

			/**
			 * Filter the auto-evaluated trust level before it is written.
			 *
			 * Listeners can lower the level (e.g. veto promotion for
			 * sandboxed users) or raise it (e.g. onboarding campaign that
			 * fast-tracks the ladder). Returning the user's current level
			 * short-circuits the write.
			 *
			 * Only fires on automatic promotion paths (cron + CLI
			 * trust-evaluate). Manual admin/CLI overrides intentionally
			 * bypass this filter.
			 *
			 * @param int   $new_level Level the evaluator chose.
			 * @param int   $user_id   Target user.
			 * @param array $stats     Stats fed to the evaluator
			 *                         (post_count, days_active, reputation,
			 *                         replies_received).
			 */
			$new_level = (int) apply_filters( 'jetonomy_trust_level_pre_change', $new_level, (int) $profile->user_id, $stats );

			if ( $new_level > (int) $profile->trust_level ) {
				$wpdb->update( $profiles_t, [ 'trust_level' => $new_level ], [ 'user_id' => $profile->user_id ] );
				do_action( 'jetonomy_trust_level_changed', (int) $profile->user_id, (int) $profile->trust_level, $new_level );
			}
		}
	}

	/**
	 * Remove expired bans/restrictions (runs hourly).
	 *
	 * Processes at most jetonomy_cron_batch_size rows per run (default 500)
	 * to avoid long-running DELETE locks on large communities.
	 */
	public function cleanup_expired_restrictions(): void {
		global $wpdb;
		$table = table( 'restrictions' );
		$batch = (int) apply_filters( 'jetonomy_cron_batch_size', 500, 'cleanup_expired_restrictions' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$batch
			)
		);
	}

	/**
	 * Prune old activity log entries (runs daily).
	 *
	 * Retention period is configurable via jetonomy_settings['activity_log_retention_days'].
	 * Defaults to 90 days. Set to 0 to keep forever.
	 * Deletes in batches of 5 000 to avoid lock contention, looping until all expired rows are removed.
	 */
	public function prune_activity_log(): void {
		global $wpdb;
		$table    = table( 'activity_log' );
		$settings = get_option( 'jetonomy_settings', [] );
		$days     = (int) ( $settings['activity_log_retention_days'] ?? 90 );
		if ( $days <= 0 ) {
			return; // 0 = keep forever.
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		// Delete in batches to avoid lock contention, loop until done.
		do {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff
				)
			);
		} while ( $deleted >= 5000 );
	}

	/**
	 * Mark old unread notifications as read (runs weekly, 30 days).
	 *
	 * Processes at most jetonomy_cron_batch_size rows per run (default 500)
	 * to avoid long-running UPDATE locks on large communities.
	 */
	public function cleanup_old_notifications(): void {
		global $wpdb;
		$table  = table( 'notifications' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$batch  = (int) apply_filters( 'jetonomy_cron_batch_size', 500, 'cleanup_old_notifications' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$table} SET is_read = 1 WHERE is_read = 0 AND created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff,
				$batch
			)
		);
	}

	/**
	 * Publish any posts whose published_at datetime has passed (runs hourly).
	 *
	 * Processes at most jetonomy_cron_batch_size due posts per run (default 500)
	 * to keep each invocation time-bounded on large communities.
	 */
	public function publish_scheduled_posts(): void {
		$batch     = (int) apply_filters( 'jetonomy_cron_batch_size', 500, 'publish_scheduled_posts' );
		$due_posts = \Jetonomy\Models\Post::get_due_scheduled( $batch );
		foreach ( $due_posts as $post ) {
			\Jetonomy\Models\Post::publish_scheduled( (int) $post->id );
		}
	}
}
