<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.9.3 — align post type with the space that holds it.
 *
 * Post type is behavioural, not cosmetic. Schema_Markup emits QAPage with
 * acceptedAnswer only for `question`, so a Q&A space whose posts were still
 * `topic` silently served DiscussionForumPosting and lost the rich result the
 * comparison chart sells (Basecamp 10210058401).
 *
 * Two ways sites got here, neither of which the owner could see:
 *   - Every importer hardcodes `topic`, so migrated content never matched a
 *     Q&A or Ideas space it was later moved into.
 *   - Changing a space's type did not retype the posts inside it until 1.9.3.
 *
 * Scope is deliberately narrow: only rows whose type disagrees with their
 * space's type are touched, and the mapping is the same
 * \Jetonomy\compose_post_type() the composer and REST create through, so this
 * cannot invent a type those paths would not produce. Forum spaces normalise to
 * `topic`, which also sweeps up rows left NULL by older writes.
 *
 * Idempotent: re-running matches nothing once aligned.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_3 {

	public function up(): void {
		global $wpdb;

		$posts  = $wpdb->prefix . 'jt_posts';
		$spaces = $wpdb->prefix . 'jt_spaces';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( $posts, $spaces ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return;
			}
		}

		// One statement per space type, so the mapping stays visible rather than
		// hidden in a CASE. Keep in step with \Jetonomy\compose_post_type().
		$map = array(
			'qa'    => 'question',
			'ideas' => 'idea',
			'feed'  => 'status',
			'forum' => 'topic',
		);

		$total = 0;
		foreach ( $map as $space_type => $post_type ) {
			$total += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$posts} p
					   JOIN {$spaces} s ON s.id = p.space_id
					    SET p.type = %s
					  WHERE s.type = %s
					    AND ( p.type IS NULL OR p.type <> %s )",
					$post_type,
					$space_type,
					$post_type
				)
			);
		}
		// phpcs:enable

		if ( $total > 0 ) {
			/**
			 * Fires after the 1.9.3 post-type alignment migration.
			 *
			 * @since 1.9.3
			 *
			 * @param int $total Rows retyped.
			 */
			do_action( 'jetonomy_posts_retyped_migration', $total );
		}
	}
}
