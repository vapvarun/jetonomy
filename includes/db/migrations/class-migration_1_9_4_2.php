<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.9.4.2 — give jt_space_members a provenance column.
 *
 * The problem this unblocks
 * -------------------------
 * Every membership adapter fires jetonomy_membership_activated and
 * jetonomy_membership_deactivated — BN Pro, PMPro, MemberPress, Woo, RCP,
 * LearnDash, Tutor, Sensei, MasterStudy, Learnomy, tags. Measured at runtime,
 * Jetonomy listened to neither: zero add_action on either hook in free or Pro.
 *
 * That is not an ACCESS bug and has not been one since 1.9.0: Permission_Engine
 * resolves access rules live, so a subscriber gets in the moment their plan is
 * active and loses access the moment it lapses, with no roster row involved.
 *
 * What was still wrong is that a paying member is not ON the roster. They can
 * read and post, but they do not appear in the space Members list, are not in
 * member_count, and are not matched by role-based settings that read
 * SpaceMember::get_role() — so `who_can_post: members` silently excludes the
 * people paying for exactly that.
 *
 * Why a column had to come first
 * ------------------------------
 * jt_space_members was (space_id, user_id, role, joined_at). With no record of
 * HOW a row got there, a deactivation handler cannot tell a tier-granted row
 * from someone who joined the space legitimately years ago — and would
 * eventually evict the wrong people. Adding the listeners without this column
 * is how you turn a cosmetic gap into member data loss, which is why 1.9.0
 * fixed the gate instead and left this card open.
 *
 * With `source` in place the deactivation handler deletes ONLY rows it can
 * prove it created (source = 'tier'). A member who joined manually and also
 * pays keeps their manual row when the plan lapses, because that row says
 * 'manual' and is never a candidate.
 *
 * Backfill is deliberately 'manual'
 * ---------------------------------
 * Every row that exists before this migration predates tier-granting, so none
 * of them can have been created by a membership event. Defaulting them to
 * 'manual' means the very first deactivation cannot touch a single pre-existing
 * row — the safe direction. The alternative (guessing which historical rows
 * came from the Sync Members button) would be inference presented as fact.
 *
 * Idempotent: SHOW COLUMNS before ALTER, so a re-run adds nothing.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_4_2 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'jt_space_members';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'source' ) );
		if ( null !== $exists ) {
			return;
		}

		$wpdb->query(
			"ALTER TABLE {$table}
			 ADD COLUMN source ENUM('manual','invite','rule','tier') NOT NULL DEFAULT 'manual'"
		);

		// Indexed because the deactivation handler's only query is
		// "this user's tier rows" — (user_id, source) rather than
		// (source, user_id), since user_id is the selective half.
		$has_key = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'user_source' ) );
		if ( null === $has_key ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY user_source (user_id, source)" );
		}
		// phpcs:enable
	}
}
