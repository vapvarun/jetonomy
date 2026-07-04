<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.6.1 — indexes for the custom XML sitemap.
 *
 * The sitemap emitter keyset-paginates public content ordered by id, so it needs
 * a covering index that satisfies WHERE + ORDER BY id without a filesort:
 *   - jt_posts  : sitemap_status_id    (status, id)
 *   - jt_spaces : sitemap_vis_status_id (visibility, status, id)
 *
 * Idempotent: each key is added only when missing.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_6_1 {

	public function up(): void {
		global $wpdb;

		$indexes = array(
			'jt_posts'  => array( 'sitemap_status_id', 'status, id' ),
			'jt_spaces' => array( 'sitemap_vis_status_id', 'visibility, status, id' ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $indexes as $suffix => $spec ) {
			$table  = $wpdb->prefix . $suffix;
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
