<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.5.0.1 — index jt_subscriptions for subscriber lookups.
 *
 * Notification fan-out calls Subscription::get_subscribers( $type, $id ), which
 * runs `WHERE object_type=? AND object_id=?`. The only existing key is
 * UNIQUE (user_id, object_type, object_id) — leading on user_id, so that query
 * could not use it and did a full table scan on every post/reply create. Add a
 * (object_type, object_id) key so the lookup (and the new COUNT) is indexed.
 *
 * Idempotent: only adds the key when it is missing.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_5_0_1 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'jt_subscriptions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$has_index = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
				'object_lookup'
			)
		);
		if ( $has_index ) {
			return;
		}

		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY object_lookup (object_type, object_id)" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
