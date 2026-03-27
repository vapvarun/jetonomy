<?php
/**
 * Migration 1.1.0 — schema updates.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_1_0 {

	public function up(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql = "CREATE TABLE {$p}jt_bookmarks (
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (user_id, post_id),
  KEY user_created (user_id, created_at)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
