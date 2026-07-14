<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.7.1 — jt_blocked_users, jt_attachments.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_7_1 {

	public function up(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql = "CREATE TABLE {$p}jt_blocked_users (
  blocker_id bigint(20) unsigned NOT NULL DEFAULT 0,
  blocked_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (blocker_id, blocked_id),
  KEY blocker_created (blocker_id, created_at),
  KEY blocked_id (blocked_id)
) $charset_collate;";

		/*
		 * Attachments live in FREE from 1.7.1 on.
		 *
		 * They used to live only in Pro (jt_pro_attachments), and that turned out to
		 * be the wrong home for the DATA. A customer who migrated a forum with Pro
		 * active and later dropped Pro — licence lapsed, downgrade — saw their
		 * attachments disappear from the page entirely: the importer had taken the old
		 * forum's markup out of the post body, and the only remaining record was in a
		 * table free cannot read. Their files were fine; the site simply had no way to
		 * show them.
		 *
		 * So the LINK is free ("this post has files on it" is forum content, not a paid
		 * feature) and Pro keeps what is genuinely Pro: the upload composer, the size
		 * and type limits, and the richer previews.
		 *
		 * The schema is UNCHANGED, so this is a RENAME, not a new table. Creating a
		 * second identical table and copying rows into it would leave two tables, two
		 * copies of the same data, and an orphan to keep in sync forever. Renaming
		 * moves every existing Pro row to its new home in one statement, with no copy
		 * and nothing to reconcile. Pro then reads the same table it always did, under
		 * its new name.
		 */
		$old = $p . 'jt_pro_attachments';
		$new = $p . 'jt_attachments';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_old = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_new = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );

		if ( $has_old && ! $has_new ) {
			// The normal path: the table simply changes name, so no rows move.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "RENAME TABLE {$old} TO {$new}" );
		} elseif ( $has_old && $has_new ) {
			/*
			 * BOTH exist, and skipping the move here would silently destroy every
			 * attachment on the site.
			 *
			 * Pro's activate() also dbDelta's this table. Plugin activation and the
			 * upgrade routine are not ordered, so a customer who reactivates Pro before
			 * free's migrator has run ends up with an EMPTY jt_attachments — and their
			 * real rows still sitting in jt_pro_attachments, now orphaned and invisible.
			 * Reproduced: 3 rows in, 0 rows out, every attachment gone from the site.
			 *
			 * So merge instead of assuming. INSERT ... SELECT with the UNIQUE KEY doing
			 * the de-duplication (IGNORE), which is safe to run twice and safe if some
			 * rows are already in the new table.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT IGNORE INTO {$new} (object_type, object_id, attachment_id, sort, created_at)
				 SELECT object_type, object_id, attachment_id, sort, created_at FROM {$old}"
			);

			// Only drop the source once every row is accounted for. If the copy came up
			// short, keep the old table so the data is still recoverable by hand.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$missing = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$old} o
				 LEFT JOIN {$new} n
				   ON n.object_type = o.object_type
				  AND n.object_id = o.object_id
				  AND n.attachment_id = o.attachment_id
				 WHERE n.id IS NULL"
			);

			if ( 0 === $missing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE {$old}" );
			}
		}

		// Runs either way: creates the table on a fresh/free-only site, and verifies
		// the schema and indexes after a rename. dbDelta is idempotent.
		$sql .= "\nCREATE TABLE {$new} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type enum('post','reply') NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  attachment_id bigint(20) unsigned NOT NULL,
  sort smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY object_attachment (object_type,object_id,attachment_id),
  KEY object (object_type,object_id,sort),
  KEY attachment (attachment_id)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
