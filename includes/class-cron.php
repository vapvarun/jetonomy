<?php
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
            add_filter( 'cron_schedules', function( $s ) {
                $s['weekly'] = [ 'interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly' ];
                return $s;
            } );
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

        foreach ( $profiles as $profile ) {
            $days_active = $profile->created_at
                ? (int) ( ( time() - strtotime( $profile->created_at ) ) / DAY_IN_SECONDS )
                : 0;

            $replies_received = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$replies_t} r INNER JOIN {$posts_t} p ON r.post_id = p.id WHERE p.author_id = %d AND r.author_id != %d AND r.status = 'publish'",
                $profile->user_id, $profile->user_id
            ) );

            $new_level = Trust_Evaluator::evaluate_level( [
                'post_count'       => (int) $profile->post_count,
                'days_active'      => $days_active,
                'reputation'       => (int) $profile->reputation,
                'replies_received' => $replies_received,
            ] );

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
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . table( 'restrictions' ) . ' WHERE expires_at IS NOT NULL AND expires_at < %s',
            current_time( 'mysql', true )
        ) );
    }

    /**
     * Prune old activity log entries (runs weekly, keeps 90 days).
     */
    public function prune_activity_log(): void {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . table( 'activity_log' ) . ' WHERE created_at < %s',
            $cutoff
        ) );
    }

    /**
     * Mark old unread notifications as read (runs weekly, 30 days).
     */
    public function cleanup_old_notifications(): void {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
        // Use direct query for the WHERE clause with date
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . table( 'notifications' ) . ' SET is_read = 1 WHERE is_read = 0 AND created_at < %s',
            $cutoff
        ) );
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
