<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.9.1 - index the membership-level lookup.
 *
 * The jt_access_rules table carried only PRIMARY and KEY space_priority (space_id,
 * priority), which serves "what gates this space?" and nothing else. Every
 * question asked the other way - "which spaces does this level open?" - was a
 * full table scan, and thirteen adapters were already asking it by hand.
 *
 * That was tolerable while the only caller ran during provisioning. It stops
 * being tolerable now: the same lookup answers a course page and the account
 * area's discussions list, so it lands on pages every logged-in member loads.
 * A scan there is a scan per request, growing with every rule on the site.
 *
 * (rule_type, rule_value) is ordered to match how it is queried. rule_type is
 * the equality half of every predicate, and putting it first also lets the
 * account list's LIKE 'lrn_%' resolve as an index range rather than a scan -
 * a trailing wildcard can use the index, a leading one could not.
 *
 * Idempotent: SHOW TABLES then SHOW INDEX, so a re-run adds nothing.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_1 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'jt_access_rules';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'rule_lookup' ) );
		if ( null === $index ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY rule_lookup (rule_type, rule_value)" );
		}
		// phpcs:enable
	}
}
