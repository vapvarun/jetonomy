<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.9.2 — query-cost indexes (caching plan WP5.1–5.3).
 *
 * Adds the three indexes the query-cost audit found missing:
 *   - jt_posts         : slug (slug) — Post::find_by_slug() full-scanned the
 *     table 6-7x per single-post view (template loader, schema markup, view).
 *     Deliberately NON-unique: Post::create() does not dedupe slugs (only the
 *     REST unique_post_slug() loop does), so a UNIQUE key would hard-fail the
 *     ALTER on any site that already carries duplicates.
 *   - jt_user_profiles : seen_reputation (last_seen_at, reputation) — covering
 *     index for the period leaderboards and rank_for_user(); both filesorted
 *     the whole table. A bare (reputation) key was considered and dropped:
 *     it would be the 4th index on the hottest-write table (every vote) and
 *     the composite serves the boards.
 *   - jt_tags          : post_count (post_count) — the tag cloud and
 *     GET /tags order by post_count with only PK + slug indexed.
 *
 * Idempotent: each key is added only when missing (same shape as 1.6.0), so
 * re-runs and dbDelta-added keys are both safe.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_2 {

	public function up(): void {
		global $wpdb;

		$indexes = array(
			'jt_posts'         => array( 'slug', 'slug' ),
			'jt_user_profiles' => array( 'seen_reputation', 'last_seen_at, reputation' ),
			'jt_tags'          => array( 'post_count', 'post_count' ),
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
