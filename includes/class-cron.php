<?php
/**
 * Scheduled tasks (cron).
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Trust\Trust_Evaluator;
use function Jetonomy\table;

class Cron {

	public function __construct() {
		// Register cron schedules
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );

		// Hook cron events
		add_action( 'jetonomy_trust_evaluation', [ $this, 'evaluate_trust_levels' ] );
		add_action( 'jetonomy_cleanup_expired', [ $this, 'cleanup_expired_restrictions' ] );
		add_action( 'jetonomy_prune_activity', [ $this, 'prune_activity_log' ] );
		add_action( 'jetonomy_cleanup_notifications', [ $this, 'cleanup_old_notifications' ] );
		add_action( 'jetonomy_publish_scheduled', [ $this, 'publish_scheduled_posts' ] );
	}

	/**
	 * Schedule all cron events on plugin activation.
	 */
	public static function schedule(): void {
		// Ensure weekly schedule exists before scheduling weekly events.
		$schedules = wp_get_schedules();
		if ( ! isset( $schedules['weekly'] ) ) {
			add_filter(
				'cron_schedules',
				function ( $s ) {
					$s['weekly'] = [
						'interval' => WEEK_IN_SECONDS,
						'display'  => 'Once Weekly',
					];
					return $s;
				}
			);
		}

		if ( ! wp_next_scheduled( 'jetonomy_trust_evaluation' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'jetonomy_trust_evaluation' );
		}
		if ( ! wp_next_scheduled( 'jetonomy_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'jetonomy_cleanup_expired' );
		}
		if ( ! wp_next_scheduled( 'jetonomy_prune_activity' ) ) {
			wp_schedule_event( time(), 'weekly', 'jetonomy_prune_activity' );
		}
		if ( ! wp_next_scheduled( 'jetonomy_cleanup_notifications' ) ) {
			wp_schedule_event( time(), 'weekly', 'jetonomy_cleanup_notifications' );
		}
		if ( ! wp_next_scheduled( 'jetonomy_publish_scheduled' ) ) {
			wp_schedule_event( time(), 'hourly', 'jetonomy_publish_scheduled' );
		}
	}

	/**
	 * Unschedule all cron events on plugin deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( 'jetonomy_trust_evaluation' );
		wp_clear_scheduled_hook( 'jetonomy_cleanup_expired' );
		wp_clear_scheduled_hook( 'jetonomy_prune_activity' );
		wp_clear_scheduled_hook( 'jetonomy_cleanup_notifications' );
		wp_clear_scheduled_hook( 'jetonomy_publish_scheduled' );
	}

	public function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => 'Once Weekly',
			];
		}
		return $schedules;
	}

	/**
	 * Evaluate and promote trust levels for all users (runs every 12h).
	 */
	public function evaluate_trust_levels(): void {
		global $wpdb;
		$profiles_t = table( 'user_profiles' );
		$replies_t  = table( 'replies' );
		$posts_t    = table( 'posts' );

		$profiles = $wpdb->get_results(
			"SELECT user_id, post_count, reply_count, reputation, trust_level, created_at FROM {$profiles_t} WHERE trust_level < 4"
		);

		if ( empty( $profiles ) ) {
			return;
		}

		$user_ids = wp_list_pluck( $profiles, 'user_id' );
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

			$new_level = Trust_Evaluator::evaluate_level(
				[
					'post_count'       => (int) $profile->post_count,
					'days_active'      => $days_active,
					'reputation'       => (int) $profile->reputation,
					'replies_received' => $replies_received,
				]
			);

			if ( $new_level > (int) $profile->trust_level ) {
				$wpdb->update( $profiles_t, [ 'trust_level' => $new_level ], [ 'user_id' => $profile->user_id ] );
				do_action( 'jetonomy_trust_level_changed', (int) $profile->user_id, (int) $profile->trust_level, $new_level );
			}
		}
	}

	/**
	 * Remove expired bans/restrictions (runs hourly).
	 */
	public function cleanup_expired_restrictions(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . table( 'restrictions' ) . ' WHERE expires_at IS NOT NULL AND expires_at < %s',
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Prune old activity log entries (runs weekly).
	 *
	 * Retention period is configurable via jetonomy_settings['activity_log_retention_days'].
	 * Defaults to 90 days. Set to 0 to keep forever.
	 * Deletes in batches of 5 000 to avoid lock contention.
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
		// Delete in batches to avoid lock contention.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Mark old unread notifications as read (runs weekly, 30 days).
	 */
	public function cleanup_old_notifications(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		// Use direct query for the WHERE clause with date
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . table( 'notifications' ) . ' SET is_read = 1 WHERE is_read = 0 AND created_at < %s',
				$cutoff
			)
		);
	}

	/**
	 * Publish any posts whose published_at datetime has passed (runs hourly).
	 */
	public function publish_scheduled_posts(): void {
		$due_posts = \Jetonomy\Models\Post::get_due_scheduled();
		foreach ( $due_posts as $post ) {
			\Jetonomy\Models\Post::publish_scheduled( (int) $post->id );
		}
	}
}
