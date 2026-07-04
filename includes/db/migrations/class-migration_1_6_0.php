<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.6.0 — scale indexes for the Wave A fixes.
 *
 * Adds the covering indexes the moderation queue, analytics top-contributors,
 * and trust-evaluation sweep need so they stop full-scanning / filesorting at
 * 100k+ rows:
 *   - jt_posts   / jt_replies      : status_created (status, created_at)
 *   - jt_votes                     : object_created (object_type, object_id, created_at)
 *   - jt_user_profiles             : trust_user (trust_level, user_id)
 *
 * Idempotent: each key is added only when it is missing, so re-runs and sites
 * where dbDelta already added the key are both safe.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_6_0 {

	public function up(): void {
		global $wpdb;

		$indexes = array(
			'jt_posts'         => array( 'status_created', 'status, created_at' ),
			'jt_replies'       => array( 'status_created', 'status, created_at' ),
			'jt_votes'         => array( 'object_created', 'object_type, object_id, created_at' ),
			'jt_user_profiles' => array( 'trust_user', 'trust_level, user_id' ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $indexes as $suffix => $spec ) {
			$table = $wpdb->prefix . $suffix;

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			list( $key_name, $columns ) = $spec;

			$has_index = $wpdb->get_var(
				$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $key_name )
			);
			if ( $has_index ) {
				continue;
			}

			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$key_name} ({$columns})" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
