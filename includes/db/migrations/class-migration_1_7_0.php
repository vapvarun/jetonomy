<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.7.0 — anonymous posting flag.
 *
 * Adds is_anonymous TINYINT(1) DEFAULT 0 to jt_posts and jt_replies. The
 * column masks the real author on display; the real author_id is always kept.
 *
 * Also adds actor_anonymous TINYINT(1) DEFAULT 0 to jt_notifications so a
 * notification row persists whether its actor's SOURCE content was anonymous
 * at creation time. Masking the message text alone isn't enough — both
 * prepare_notification() (REST) and the notifications template independently
 * re-resolve actor_id to the real user's avatar/name/profile link, so this
 * flag is the source of truth those display layers gate on instead of
 * re-deriving the source object.
 *
 * Idempotent: each column is added only when missing (SHOW COLUMNS guard), so
 * re-runs and sites where dbDelta already added it are both safe.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_7_0 {

	public function up(): void {
		global $wpdb;

		// suffix => [ column, AFTER column ].
		$specs = array(
			'jt_posts'         => array( 'is_anonymous', 'author_id' ),
			'jt_replies'       => array( 'is_anonymous', 'author_id' ),
			'jt_notifications' => array( 'actor_anonymous', 'actor_id' ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $specs as $suffix => list( $column, $after ) ) {
			$table = $wpdb->prefix . $suffix;

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			$has_col = $wpdb->get_var(
				$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column )
			);
			if ( $has_col ) {
				continue;
			}

			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} tinyint(1) NOT NULL DEFAULT 0 AFTER {$after}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
