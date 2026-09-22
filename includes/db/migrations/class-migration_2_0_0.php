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
	}
}
