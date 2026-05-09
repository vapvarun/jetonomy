<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.4.2.2 - align Ideas roadmap status enum with the documented 4-lane spec.
 *
 * The 1.4.2 readme advertised four roadmap status lanes (planned, in progress,
 * shipped, declined) but the schema and admin UI shipped six lanes from the
 * earlier 1.4.2.1 backfill (submitted, under_review, planned, in_progress,
 * completed, declined). This migration brings the data and the column type
 * into alignment with the readme.
 *
 * Data remap end-state:
 *
 *   - 'submitted'    -> NULL    (newly posted ideas live in the space's
 *                                 normal feed; they enter the roadmap only
 *                                 when an owner explicitly assigns a status)
 *   - 'under_review' -> NULL    (pre-decision state, same semantics as NULL)
 *   - 'completed'    -> 'shipped' (rename only - same meaning, customer-facing
 *                                  word "shipped" matches the readme)
 *
 * Sequence is non-obvious: the new value 'shipped' is not in the old ENUM,
 * so we cannot UPDATE rows to 'shipped' before extending the ENUM. Order:
 *
 *   1. NULL out 'submitted' and 'under_review' rows (still valid values).
 *   2. ALTER ENUM to a transitional set that contains BOTH old and new
 *      ('completed' AND 'shipped' simultaneously) so step 3 can succeed.
 *   3. UPDATE 'completed' rows to 'shipped'.
 *   4. ALTER ENUM to the final 4-value spec.
 *
 * Doing the rename through a single ALTER (drop 'completed' + add 'shipped')
 * would cause MySQL to coerce existing 'completed' rows to '' (empty string)
 * before the UPDATE could rename them.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_4_2_2 {

	public function up(): void {
		global $wpdb;
		$posts_table = $wpdb->prefix . 'jt_posts';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Step 1: drop the two pre-decision states. NULL is still permitted
		// by the column DEFAULT, so this is a no-coercion update.
		$wpdb->query(
			"UPDATE {$posts_table} SET idea_status = NULL WHERE idea_status IN ('submitted','under_review')"
		);

		// Step 2: transitional ENUM that includes both 'completed' and the
		// new 'shipped' value, so step 3's UPDATE has a valid target.
		$wpdb->query(
			"ALTER TABLE {$posts_table}
			 MODIFY COLUMN idea_status ENUM('submitted','under_review','planned','in_progress','completed','shipped','declined') DEFAULT NULL"
		);

		// Step 3: rename 'completed' rows to 'shipped'.
		$wpdb->query(
			"UPDATE {$posts_table} SET idea_status = 'shipped' WHERE idea_status = 'completed'"
		);

		// Step 3b: rescue rows that were coerced to '' on a previous (broken)
		// run of this migration that ALTERed before UPDATEing. Safe no-op on
		// fresh installs because '' is not used anywhere as a real value.
		$wpdb->query(
			"UPDATE {$posts_table} SET idea_status = NULL WHERE idea_status = ''"
		);

		// Step 4: final ENUM in the documented 4-value form.
		$wpdb->query(
			"ALTER TABLE {$posts_table}
			 MODIFY COLUMN idea_status ENUM('planned','in_progress','shipped','declined') DEFAULT NULL"
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
