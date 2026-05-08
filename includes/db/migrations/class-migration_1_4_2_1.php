<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.4.2.1 — backfill posts.idea_status for Ideas spaces.
 *
 * Pre-1.4.3 the Ideas space type advertised a roadmap workflow but had
 * no actual status field driving it. The roadmap kanban inferred columns
 * from `is_resolved` / `is_closed` (which mean different things) plus a
 * `reply_count > 0` heuristic that auto-promoted ideas to "In Progress"
 * the moment any member commented. The promise of a roadmap that owners
 * could curate was effectively unreachable.
 *
 * The schema already had a dormant `idea_status` enum column. This
 * migration pre-populates it for existing posts on Ideas spaces so the
 * new explicit-status workflow has sane initial values:
 *
 *   - `is_resolved = 1` → 'completed' (the QA accept-answer flow had
 *     been the only way to hit this flag, but on Ideas spaces a few
 *     installs had toggled it manually as a "shipped" signal).
 *   - `is_closed = 1`   → 'declined'  ("won't fix" reading).
 *   - everything else   → 'submitted' (default open state).
 *
 * Posts on non-Ideas spaces are left alone; their idea_status stays NULL
 * because the column means nothing for those types.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_4_2_1 {

	public function up(): void {
		global $wpdb;
		$posts_table  = $wpdb->prefix . 'jt_posts';
		$spaces_table = $wpdb->prefix . 'jt_spaces';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$posts_table} p
				 JOIN {$spaces_table} s ON s.id = p.space_id
				 SET p.idea_status = %s
				 WHERE s.type = %s AND p.idea_status IS NULL AND p.is_resolved = 1",
				'completed',
				'ideas'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$posts_table} p
				 JOIN {$spaces_table} s ON s.id = p.space_id
				 SET p.idea_status = %s
				 WHERE s.type = %s AND p.idea_status IS NULL AND p.is_closed = 1",
				'declined',
				'ideas'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$posts_table} p
				 JOIN {$spaces_table} s ON s.id = p.space_id
				 SET p.idea_status = %s
				 WHERE s.type = %s AND p.idea_status IS NULL",
				'submitted',
				'ideas'
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
