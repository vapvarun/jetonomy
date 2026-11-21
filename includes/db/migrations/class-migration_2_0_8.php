<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * 2.0.8: jt_subscriptions.notify_via can say "none".
 *
 * A topic's author who clicks Unfollow keeps their row as 'none' so they stop being told of
 * replies, while an imported topic with no row at all keeps notifying its author. Appending a
 * value to the end of an ENUM is a metadata-only change in MySQL: the table is not rebuilt.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_8 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_subscriptions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN notify_via ENUM('web','email','both','none') NOT NULL DEFAULT 'web'" );
	}
}
