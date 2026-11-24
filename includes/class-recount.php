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
			return array();
		}

		$profiles_table = table( 'user_profiles' );
		$posts_table    = table( 'posts' );
		$replies_table  = table( 'replies' );
		// One %d per id, bound by prepare(): the list is ints already, but a placeholder list cannot be wrong.
		$in = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$stats = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats['user_post_counts'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$profiles_table} u SET u.post_count = (SELECT COUNT(*) FROM {$posts_table} p WHERE p.author_id = u.user_id AND p.status = 'publish') WHERE u.user_id IN ({$in})", $user_ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats['user_reply_counts'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$profiles_table} u SET u.reply_count = (SELECT COUNT(*) FROM {$replies_table} r INNER JOIN {$posts_table} rp ON rp.id = r.post_id WHERE r.author_id = u.user_id AND r.status = 'publish' AND rp.status = 'publish') WHERE u.user_id IN ({$in})", $user_ids ) );

		// Named rows, so the profile caches drop precisely rather than flushing
		// the whole group the way run() has to. Same key UserProfile writes.
		Cache::delete_many( array_map( static fn( $uid ) => "profile:{$uid}", $user_ids ) );

		return $stats;
	}

	/**
	 * Rebuild denormalized counters.
	 *
	 * Optionally scoped to a half-open id range, which is what lets the 2.0.0
	 * backfill ({@see Recount_Backfill}) walk a large site in bounded slices
	 * without a second copy of these statements. The range applies to the
	 * driving table's primary key for the requested $type - post id, space id,
	 * or profile user_id - so it is meaningless for 'all' and ignored there.
	 *
	 * The range is half-open - [$from_id, $until_id) - so consecutive slices
	 * tile the key space with no gap and no overlap, and the first slice
	 * includes key 0. An exclusive lower bound skipped it, which on this schema
	 * meant a user_profiles row at user_id 0 kept its drift forever.
	 *
	 * @param string   $type     One of: all, posts, spaces, votes, users.
	 * @param int|null $from_id  Inclusive lower bound on the driving key.
	 * @param int|null $until_id Exclusive upper bound on the driving key.
	 * @return array<string, int> Map of step => rows_affected.
	 */
	public static function run( string $type = 'all', ?int $from_id = null, ?int $until_id = null ): array {
		global $wpdb;

		$type = in_array( $type, array( 'all', 'posts', 'spaces', 'votes', 'users' ), true ) ? $type : 'all';

		// A range needs one driving table to apply to; 'all' spans four.
		$scoped = 'all' !== $type && null !== $from_id && null !== $until_id;

		/**
		 * WHERE clause bounding one batch, or '' for the whole table.
		 *
		 * @param string $key Fully-qualified key column, e.g. 'p.id'.
		 * @return string
		 */
		$range = static function ( string $key ) use ( $scoped, $from_id, $until_id ): string {
			return $scoped
				? sprintf( ' WHERE %s >= %d AND %s < %d', $key, (int) $from_id, $key, (int) $until_id )
				: '';
		};

		$posts_table    = table( 'posts' );
		$replies_table  = table( 'replies' );
		$spaces_table   = table( 'spaces' );
		$votes_table    = table( 'votes' );
		$cats_table     = table( 'categories' );
		$profiles_table = table( 'user_profiles' );

		$stats = array();

		if ( in_array( $type, array( 'all', 'posts' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['post_reply_counts'] = (int) $wpdb->query( "UPDATE {$posts_table} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_table} r WHERE r.post_id = p.id AND r.status = 'publish')" . $range( 'p.id' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['post_last_reply_at'] = (int) $wpdb->query( "UPDATE {$posts_table} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_table} r WHERE r.post_id = p.id AND r.status = 'publish')" . $range( 'p.id' ) );
		}

		if ( in_array( $type, array( 'all', 'spaces' ), true ) ) {
			$members_table = table( 'space_members' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['space_post_counts'] = (int) $wpdb->query( "UPDATE {$spaces_table} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_table} p WHERE p.space_id = s.id AND p.status = 'publish')" . $range( 's.id' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['space_member_counts'] = (int) $wpdb->query( "UPDATE {$spaces_table} s SET s.member_count = (SELECT COUNT(*) FROM {$members_table} m WHERE m.space_id = s.id)" . $range( 's.id' ) );
			// Driven by category id, not space id, so a space-range batch cannot
			// bound it meaningfully - and categories are few enough that the
			// unscoped pass in Migration_2_0_0 covers an upgrading site once.
			if ( ! $scoped ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stats['category_space_counts'] = (int) $wpdb->query( "UPDATE {$cats_table} c SET c.space_count = (SELECT COUNT(*) FROM {$spaces_table} s WHERE s.category_id = c.id AND s.status = 'active')" );
			}
		}

		if ( in_array( $type, array( 'all', 'votes' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['post_vote_scores'] = (int) $wpdb->query( "UPDATE {$posts_table} p SET p.vote_score = COALESCE((SELECT SUM(v.value) FROM {$votes_table} v WHERE v.object_type = 'post' AND v.object_id = p.id), 0)" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['reply_vote_scores'] = (int) $wpdb->query( "UPDATE {$replies_table} r SET r.vote_score = COALESCE((SELECT SUM(v.value) FROM {$votes_table} v WHERE v.object_type = 'reply' AND v.object_id = r.id), 0)" );
		}

		if ( in_array( $type, array( 'all', 'users' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['user_post_counts'] = (int) $wpdb->query( "UPDATE {$profiles_table} u SET u.post_count = (SELECT COUNT(*) FROM {$posts_table} p WHERE p.author_id = u.user_id AND p.status = 'publish')" . $range( 'u.user_id' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats['user_reply_counts'] = (int) $wpdb->query( "UPDATE {$profiles_table} u SET u.reply_count = (SELECT COUNT(*) FROM {$replies_table} r INNER JOIN {$posts_table} rp ON rp.id = r.post_id WHERE r.author_id = u.user_id AND r.status = 'publish' AND rp.status = 'publish')" . $range( 'u.user_id' ) );
		}

		// Every recompute above is a set-based UPDATE that cannot name the rows it
		// touched (Caching Standard §4d), and the values written (space/profile
		// counts, vote scores) back the space:{id} / profile:{id} caches. This is a
		// one-shot admin/CLI path, so flush the group rather than track every id.
		//
		// A scoped batch skips it: the backfill runs hundreds of these, and
		// flushing the whole group per slice would keep a large site's cache
		// cold for the length of the job. Recount_Backfill flushes once when
		// the last batch lands.
		if ( ! $scoped ) {
			Cache::flush();
		}

		return $stats;
	}
}
