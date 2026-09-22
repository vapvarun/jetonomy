<?php
/**
 * Migration 2.0.0 — the importer's source-row map.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates jt_import_map.
 *
 * The table is also declared in Schema so a FRESH install gets it: activate()
 * calls create_tables() and stamps db_version at the current release, so no
 * migration ever runs on a new site. A table that exists only here would never
 * be created there - the mistake jt_attachments documents in Schema.
 */
class Migration_2_0_0 {

	/**
	 * Create the table.
	 *
	 * The dbDelta call is idempotent, so running this on a site whose Schema pass
	 * already created the table is a no-op rather than an error.
	 *
	 * @return void
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$p               = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$p}jt_import_map (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(32) NOT NULL,
  object_type varchar(20) NOT NULL,
  source_id varchar(64) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY source_row (source,object_type,source_id),
  KEY object (object_type,object_id)
) ENGINE=InnoDB $charset_collate;"
		);

		// One-off recount of jt_categories.space_count.
		//
		// The WRITE path is fixed (Space::update now maintains the count on both
		// sides of a category move), but an existing site keeps whatever drift it
		// accumulated before that - one site here had a category stored at 23 with
		// 1 actual space. Nothing surfaces the discrepancy to an owner, so without
		// this they carry a wrong number until somebody happens to run
		// `wp jetonomy recount`. Same statement Recount::run() uses, so there is
		// one definition of the right answer.
		$cats_t   = $p . 'jt_categories';
		$spaces_t = $p . 'jt_spaces';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefixed table names.
		$wpdb->query( "UPDATE {$cats_t} c SET c.space_count = (SELECT COUNT(*) FROM {$spaces_t} s WHERE s.category_id = c.id AND s.status = 'active')" );
	}
}
