<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.7.0 — anonymous posting flag.
 *
 * Adds is_anonymous TINYINT(1) DEFAULT 0 to jt_posts and jt_replies. The
 * column masks the real author on display; the real author_id is always kept.
 *
 * Idempotent: the column is added only when missing (SHOW COLUMNS guard), so
 * re-runs and sites where dbDelta already added it are both safe.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_7_0 {

	public function up(): void {
		global $wpdb;

		$tables = array( 'jt_posts', 'jt_replies' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $tables as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			$has_col = $wpdb->get_var(
				$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'is_anonymous' )
			);
			if ( $has_col ) {
				continue;
			}

			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN is_anonymous tinyint(1) NOT NULL DEFAULT 0 AFTER author_id" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
