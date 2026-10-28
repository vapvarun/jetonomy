<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * 2.0.7: an access rule's stored roster role follows what it grants.
 *
 * Until 2.0.7 a rule created over REST (or any writer that sent no role)
 * was stored as Viewer even when it granted Participate or Full. The
 * role is now derived from the grant on every write; this corrects rows
 * saved before. "Full" is kept only on role and capability rules, the
 * only ones where it can differ from Participate; elsewhere it becomes
 * Participate, which is what those members already got.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_7 {

	public function up(): void {
		global $wpdb;
		$rules = $wpdb->prefix . 'jt_access_rules';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$rules} SET grants = 'participate' WHERE grants = 'full' AND rule_type NOT IN ( 'role', 'capability' )" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$rules} SET space_role = IF( grants = 'read', 'viewer', 'member' )" );
		\Jetonomy\Cache::flush();
	}
}
