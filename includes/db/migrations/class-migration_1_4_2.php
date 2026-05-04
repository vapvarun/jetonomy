<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.4.2 — coerce hidden spaces to invite-only join_policy.
 *
 * Pre-1.4.2 the create + update paths (REST, admin AJAX, CLI) validated
 * `visibility` and `join_policy` in isolation, so a space could be saved
 * as `visibility = hidden` with `join_policy = open` or `approval`. The
 * gate template in 1.4.1 then happily rendered a one-click Join Space
 * button for any logged-in user who knew the slug, defeating the point
 * of hiding the space. Validators landed in 1.4.2; this migration cleans
 * up rows already saved with the bad combo so the next read is sane.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_4_2 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_spaces';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET join_policy = %s WHERE visibility = %s AND join_policy <> %s",
				'invite',
				'hidden',
				'invite'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
