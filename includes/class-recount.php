<?php
/**
 * Recount service — rebuilds denormalized counters from the canonical tables.
 *
 * Shared between the WP-CLI `wp jetonomy recount` command and the REST endpoint
 * `POST /jetonomy/v1/admin/recount`.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

class Recount {

	/**
	 * Recount post/reply totals for specific members only.
	 *
	 * The full run( 'users' ) rewrites EVERY profile row in one set-based
	 * UPDATE, which is right for an admin "fix my counters" action and wrong as
	 * a side effect
	 * of deleting one space: a 100k-member site would rewrite 100k rows and
	 * flush the whole cache because a purge touched a dozen authors.
	 *
	 * Same two statements, scoped. Kept here beside run() so the definition of
	 * "what post_count means" lives in one file - a second copy would be free
	 * to drift on the status filter, which is the part that is easy to get
	 * wrong.
	 *
	 * @param int[] $user_ids Members whose totals to rebuild.
	 * @return array<string,int> Rows updated per counter.
	 */
	public static function for_users( array $user_ids ): array {
		global $wpdb;

		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return [];
		}

		$profiles_t = table( 'user_profiles' );
		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$in         = implode( ',', $user_ids );

		$stats = [];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats['user_post_counts'] = (int) $wpdb->query( "UPDATE {$profiles_t} u SET u.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.author_id = u.user_id AND p.status = 'publish') WHERE u.user_id IN ({$in})" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats['user_reply_counts'] = (int) $wpdb->query( "UPDATE {$profiles_t} u SET u.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.author_id = u.user_id AND r.status = 'publish') WHERE u.user_id IN ({$in})" );

		// Named rows, so the profile caches drop precisely rather than flushing
		// the whole group the way run() has to. Same key UserProfile writes.
		Cache::delete_many( array_map( static fn( $uid ) => "profile:{$uid}", $user_ids ) );

		return $stats;
	}

	/**
	 * Rebuild denormalized counters.
	 *
	 * @param string $type One of: all, posts, spaces, votes, users.
	 * @return array<string, int> Map of step => rows_affected.
	 */
	public static function run( string $type = 'all' ): array {
		global $wpdb;

		$type = in_array( $type, array( 'all', 'posts', 'spaces', 'votes', 'users' ), true ) ? $type : 'all';

		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$spaces_t   = table( 'spaces' );
		$votes_t    = table( 'votes' );
		$cats_t     = table( 'categories' );
		$profiles_t = table( 'user_profiles' );

		$stats = array();

		if ( in_array( $type, array( 'all', 'posts' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['post_reply_counts'] = (int) $wpdb->query( "UPDATE {$posts_t} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['post_last_reply_at'] = (int) $wpdb->query( "UPDATE {$posts_t} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );
		}

		if ( in_array( $type, array( 'all', 'spaces' ), true ) ) {
			$members_t = table( 'space_members' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['space_post_counts'] = (int) $wpdb->query( "UPDATE {$spaces_t} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.space_id = s.id AND p.status = 'publish')" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['space_member_counts'] = (int) $wpdb->query( "UPDATE {$spaces_t} s SET s.member_count = (SELECT COUNT(*) FROM {$members_t} m WHERE m.space_id = s.id)" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['category_space_counts'] = (int) $wpdb->query( "UPDATE {$cats_t} c SET c.space_count = (SELECT COUNT(*) FROM {$spaces_t} s WHERE s.category_id = c.id AND s.status = 'active')" );
		}

		if ( in_array( $type, array( 'all', 'votes' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['post_vote_scores'] = (int) $wpdb->query( "UPDATE {$posts_t} p SET p.vote_score = COALESCE((SELECT SUM(v.value) FROM {$votes_t} v WHERE v.object_type = 'post' AND v.object_id = p.id), 0)" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['reply_vote_scores'] = (int) $wpdb->query( "UPDATE {$replies_t} r SET r.vote_score = COALESCE((SELECT SUM(v.value) FROM {$votes_t} v WHERE v.object_type = 'reply' AND v.object_id = r.id), 0)" );
		}

		if ( in_array( $type, array( 'all', 'users' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['user_post_counts'] = (int) $wpdb->query( "UPDATE {$profiles_t} u SET u.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.author_id = u.user_id AND p.status = 'publish')" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['user_reply_counts'] = (int) $wpdb->query( "UPDATE {$profiles_t} u SET u.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.author_id = u.user_id AND r.status = 'publish')" );
		}

		// Every recompute above is a set-based UPDATE that cannot name the rows it
		// touched (Caching Standard §4d), and the values written (space/profile
		// counts, vote scores) back the space:{id} / profile:{id} caches. This is a
		// one-shot admin/CLI path, so flush the group rather than track every id.
		Cache::flush();

		return $stats;
	}
}
