<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.5.0 — drop the three tables that never shipped a feature.
 *
 * Table jt_user_interests had ZERO entry points (no writer, no reader, no REST,
 * no admin, no frontend — only the schema CREATE and a privacy-eraser
 * delete), and jt_space_tags / jt_space_tag_map were never written by any
 * code path, so their read endpoint could only ever return an empty list
 * (../jetonomy-pro/audit/free/full-audit-2026-06-11.md, A5). Pure dead weight in every install.
 *
 * Guarded: each table is dropped only when it is empty. Rows could only
 * exist via third-party SQL — if any are found the table is left alone, so
 * the migration can never destroy data the plugin didn't create.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_5_0 {

	public function up(): void {
		global $wpdb;

		$tables = array(
			'jt_space_tag_map',
			'jt_space_tags',
			'jt_user_interests',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $tables as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			if ( $rows > 0 ) {
				// Third-party data — never destroy what we didn't create.
				continue;
			}

			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
