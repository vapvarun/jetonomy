<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.4.4 — denormalised open-flag counter on posts.
 *
 * Adds jt_posts.flag_count (number of pending flags on the post) so the
 * moderator "this topic has open reports" indicator is a column read instead
 * of a COUNT query per post — the same denormalised-counter pattern the table
 * already uses for reply_count / vote_score, and the only shape that scales to
 * listing pages without an N+1. Backfills the count from existing pending flags.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_4_4 {

	public function up(): void {
		global $wpdb;
		$posts = $wpdb->prefix . 'jt_posts';
		$flags = $wpdb->prefix . 'jt_flags';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Add the column if an earlier install doesn't have it yet.
		$has_column = $wpdb->get_results( "SHOW COLUMNS FROM {$posts} LIKE 'flag_count'" );
		if ( empty( $has_column ) ) {
			$wpdb->query( "ALTER TABLE {$posts} ADD COLUMN flag_count int(11) NOT NULL DEFAULT 0 AFTER view_count" );
		}

		// Backfill from existing pending flags so the indicator is correct on
		// upgrade, not just for flags filed after this release.
		$wpdb->query(
			"UPDATE {$posts} p
			 SET p.flag_count = (
				SELECT COUNT(*) FROM {$flags} f
				WHERE f.object_type = 'post' AND f.object_id = p.id AND f.status = 'pending'
			 )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
