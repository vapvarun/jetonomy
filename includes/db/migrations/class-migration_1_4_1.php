<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.4.1 — add verification_reminder_sent_at to jt_user_profiles.
 *
 * Tracks the moment we sent the (single) "you still haven't verified your
 * email" reminder for an unverified account. The column is the rate-limit:
 * the cron's WHERE clause excludes any row where it is NOT NULL, so each
 * user receives at most one reminder. Nullable so existing rows backfill
 * safely without a default-time.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_4_1 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_user_profiles';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'verification_reminder_sent_at'
			)
		);

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN verification_reminder_sent_at datetime DEFAULT NULL AFTER updated_at" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
