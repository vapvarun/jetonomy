<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.2.5 — backfill jt_space_members from historical posts + replies.
 *
 * Before 1.3.4, posting to a space did not auto-join the author — only the
 * space creator appeared in `jt_space_members`, so `space.member_count` was
 * stuck at 1 for every space regardless of how many real authors had
 * participated. The 1.3.4 auto-join fix only helps going forward; this
 * migration repairs history in one pass.
 *
 * For every (space_id, author_id) pair present in published posts or replies
 * that isn't already in `jt_space_members`, insert a row with role=member.
 * Then call Recount::run('spaces') so `space.member_count` reflects reality.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Recount;
use function Jetonomy\now;
use function Jetonomy\table;

class Migration_1_2_5 {

	public function up(): void {
		global $wpdb;

		$posts_t   = table( 'posts' );
		$replies_t = table( 'replies' );
		$members_t = table( 'space_members' );
		$joined_at = now();

		// Insert any missing (space_id, author_id) pairs from published posts.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$members_t} (space_id, user_id, role, joined_at)
				 SELECT DISTINCT p.space_id, p.author_id, 'member', %s
				 FROM {$posts_t} p
				 LEFT JOIN {$members_t} m ON m.space_id = p.space_id AND m.user_id = p.author_id
				 WHERE p.status = 'publish'
				   AND p.author_id > 0
				   AND m.user_id IS NULL",
				$joined_at
			)
		);

		// Same backfill from replies — inner-join to posts to get the space_id.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$members_t} (space_id, user_id, role, joined_at)
				 SELECT DISTINCT p.space_id, r.author_id, 'member', %s
				 FROM {$replies_t} r
				 INNER JOIN {$posts_t} p ON p.id = r.post_id
				 LEFT JOIN {$members_t} m ON m.space_id = p.space_id AND m.user_id = r.author_id
				 WHERE r.status = 'publish'
				   AND r.author_id > 0
				   AND m.user_id IS NULL",
				$joined_at
			)
		);
		// phpcs:enable

		// Refresh space.member_count so admin dashboards show the real numbers.
		Recount::run( 'spaces' );

		// Also sync the per-space member_count column directly — Recount::run doesn't
		// rebuild member_count (only post_count + category space_count). Do it here.
		$spaces_t = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"UPDATE {$spaces_t} s SET s.member_count = (SELECT COUNT(*) FROM {$members_t} m WHERE m.space_id = s.id)"
		);

		// This direct member_count UPDATE runs after Recount::run()'s own flush and
		// is set-based (names no ids, §4d); space.member_count backs space:{id}, so
		// flush again after it. One-shot upgrade path, so a group flush is fine.
		\Jetonomy\Cache::flush();
	}
}
