<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * 2.0.1.1: indexes for large communities.
 *
 * - jt_posts status_votes: the sidebar's site-wide top topics read their
 *   five rows straight off the index instead of sorting every topic.
 * - jt_subscriptions object_user: subscriber fan-out walks one object's
 *   subscribers in user_id batches. Replaces object_lookup, its prefix.
 * - jt_user_profiles reputation_user: leaderboard counts and rank lookups
 *   (reputation > x) become range reads.
 * - jt_notifications read_created: the weekly old-unread sweep stops
 *   scanning the whole table.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_1_1 {

	public function up(): void {
		global $wpdb;

		$keys = array(
			'jt_posts'         => array( 'status_votes', '(status, vote_score, reply_count)' ),
			'jt_subscriptions' => array( 'object_user', '(object_type, object_id, user_id)' ),
			'jt_user_profiles' => array( 'reputation_user', '(reputation, user_id)' ),
			'jt_notifications' => array( 'read_created', '(is_read, created_at)' ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $keys as $short => [ $name, $columns ] ) {
			$table = $wpdb->prefix . $short;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			if ( null === $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD KEY {$name} {$columns}" );
			}
		}

		$subs = $wpdb->prefix . 'jt_subscriptions';
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$subs} WHERE Key_name = %s", 'object_lookup' ) )
			&& null !== $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$subs} WHERE Key_name = %s", 'object_user' ) ) ) {
			$wpdb->query( "ALTER TABLE {$subs} DROP KEY object_lookup" );
		}
		// phpcs:enable
	}
}
