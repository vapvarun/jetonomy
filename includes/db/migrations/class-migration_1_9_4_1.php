<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.9.4.1 — drop jt_user_profiles.display_name, but only when empty.
 *
 * Why the column is dead
 * ----------------------
 * It had zero production writers: nothing in free wrote it, nothing in Pro
 * wrote it, and the only writes anywhere were the demo seeder and QA fixtures.
 * Five files nevertheless BRANCHED on it and preferred it over the WP value —
 * article:author meta, the oEmbed author_name, pending join requests, the
 * join-request rows in space-members.php, and Pro's og:author. Every one of
 * those was an always-false branch, and one ran a UserProfile::find_by_user()
 * per row inside a foreach whose result was then discarded — a dead N+1.
 *
 * The identity consolidation (free 40af35c, pro d6021b0) removed all five
 * branches and routed every byline through \Jetonomy\user_display_name(). The
 * column has been written by nothing AND read by nothing since.
 *
 * Why this is guarded rather than an unconditional DROP
 * -----------------------------------------------------
 * Dropping a column is one-way. "No writer in free or Pro" is not the same as
 * "no writer anywhere": a site's own snippet, an importer, or a bespoke
 * integration could have populated it, and this migration runs unattended on
 * every install that updates. So it drops ONLY when every row is empty. A site
 * that put data there keeps its column and its data, and the mismatch is
 * recorded in an option a human can go and look at rather than a fatal or a
 * silent skip.
 *
 * The cost of that caution is that the column can survive on a small number of
 * sites, which is the correct trade: an orphaned empty column costs bytes, and
 * a wrong DROP costs somebody's data.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_4_1 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'jt_user_profiles';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// Already dropped, or an install created after the column was removed
		// from Schema::create_tables(). Either way there is nothing to do.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'display_name' ) );
		if ( null === $exists ) {
			return;
		}

		$populated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE display_name <> '' AND display_name IS NOT NULL" );

		if ( $populated > 0 ) {
			// Leave the column and say so. An admin notice would be noise on a
			// site that never asked about this; an option is inspectable by
			// whoever is actually investigating.
			update_option(
				'jetonomy_display_name_drop_skipped',
				array(
					'rows'       => $populated,
					'checked_at' => current_time( 'mysql', true ),
				),
				false
			);
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN display_name" );
		delete_option( 'jetonomy_display_name_drop_skipped' );
		// phpcs:enable
	}
}
