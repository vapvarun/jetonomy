<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.8.1 — private replies.
 *
 * Adds is_private TINYINT(1) DEFAULT 0 to jt_replies plus a covering index on
 * (post_id, is_private). Topics have carried is_private since 1.3.6; replies
 * could not, so sensitive information shared IN a reply on a public topic
 * stayed public (Basecamp 9804279999).
 *
 * Read-side follows the blocked-author precedent: private replies are
 * TOMBSTONED for unauthorized viewers (author, topic author, admins, and
 * space staff see the text) — never row-filtered — so reply_permalink()'s
 * page computation and raw reply counts stay identical for every viewer.
 *
 * Idempotent: column added only when missing (SHOW COLUMNS guard).
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_8_1 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'jt_replies';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'is_private' ) );
		if ( null === $column ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_private tinyint(1) NOT NULL DEFAULT 0 AFTER is_anonymous" );
		}

		$index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'post_private' ) );
		if ( null === $index ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY post_private (post_id, is_private)" );
		}
		// phpcs:enable
	}
}
