<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.9.4 — index the two lookups that had none.
 *
 * Index 1 - jt_replies.post_parent (post_id, parent_id)
 * -----------------------------------------------------
 * Reply::get_threaded() walks a post's replies one depth level at a time:
 *
 *   WHERE post_id = %d AND status = 'publish' AND parent_id IN (…)
 *
 * post_created (post_id, created_at) already stops this being a table scan, so
 * the query was never as bad as a naive `WHERE parent_id IN (…)` EXPLAIN
 * suggests — measured on 1.9.4 it examines the post's own replies, not the
 * whole table. What it could not do is SEEK to the children: every level
 * re-examines all of that post's rows to find the handful whose parent_id
 * matches. On a 40-reply topic that is free. On the 2000-reply topic the
 * big-site standard calls for, it is 2000 row examinations per level of
 * nesting. Leading with post_id keeps the existing equality, and adding
 * parent_id lets the IN() resolve against the index instead of by filtering.
 *
 * created_at is deliberately NOT appended. The IN() is a range, so MySQL
 * cannot also use a trailing column to satisfy ORDER BY created_at — it would
 * widen the key for nothing.
 *
 * Index 2 - jt_restrictions.type_reason (type, reason(64))
 * --------------------------------------------------------
 * Restriction::is_ip_banned() runs on every anonymous submission:
 *
 *   WHERE type = 'ip_ban' AND reason = %s AND (expires_at IS NULL OR …)
 *
 * user_type_space leads with user_id, which an IP check has no value for, and
 * expires(expires_at) cannot be used through the OR … IS NULL. So the check
 * full-scanned jt_restrictions — confirmed key=NULL — on the hot
 * unauthenticated write path, getting slower for every ban the site adds.
 * is_ip_banned() memoises per request, so this is once per request rather
 * than once per row, which is why it stayed invisible.
 *
 * reason is TEXT, so MySQL requires a prefix length. 64 covers IPv6's 45-char
 * maximum with headroom; the column stores an address here, not prose.
 *
 * A note on why this migration matters more than its own two indexes
 * ------------------------------------------------------------------
 * JETONOMY_DB_VERSION was left at 1.9.2 when 1.9.3 shipped. check_db_version()
 * only enters the upgrade block when the STORED version is strictly less than
 * the constant, so every site already stamped 1.9.2 skipped the block entirely
 * and Migration_1_9_3 — which realigns post type with space type, and decides
 * whether a Q&A space emits QAPage or DiscussionForumPosting — never ran.
 * Reproduced on a 1.9.3 site: 258 rows with an empty type and 42 feed-space
 * rows still typed 'topic'. Bumping the constant to 1.9.4 is what actually
 * releases that migration; these indexes ride along.
 *
 * Idempotent: SHOW TABLES then SHOW INDEX per key, so a re-run adds nothing.
 * dbDelta is unreliable for index changes, which is why this is explicit even
 * though both keys are also declared in Schema::create_tables().
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_4 {

	public function up(): void {
		global $wpdb;

		$keys = array(
			'jt_replies'      => array( 'post_parent', 'post_parent (post_id, parent_id)' ),
			'jt_restrictions' => array( 'type_reason', 'type_reason (type, reason(64))' ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $keys as $suffix => $key ) {
			list( $name, $definition ) = $key;

			$table = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) );
			if ( null === $exists ) {
				$wpdb->query( "ALTER TABLE {$table} ADD KEY {$definition}" );
			}
		}
		// phpcs:enable
	}
}
