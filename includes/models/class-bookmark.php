<?php
/**
 * Bookmark model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use function Jetonomy\table;

class Bookmark extends Model {

	protected static function table_name(): string {
		return 'bookmarks';
	}

	/**
	 * Toggle bookmark — add if missing, remove if exists.
	 *
	 * @return array{bookmarked: bool}
	 */
	public static function toggle( int $user_id, int $post_id ): array {
		if ( self::is_bookmarked( $user_id, $post_id ) ) {
			self::remove( $user_id, $post_id );
			return [ 'bookmarked' => false ];
		}

		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'INSERT IGNORE INTO ' . static::table() . ' (user_id, post_id, created_at) VALUES (%d, %d, %s)',
				$user_id,
				$post_id,
				now()
			)
		);

		return [ 'bookmarked' => true ];
	}

	public static function is_bookmarked( int $user_id, int $post_id ): bool {
		return null !== static::db()->get_row(
			static::db()->prepare(
				'SELECT user_id FROM ' . static::table() . ' WHERE user_id = %d AND post_id = %d LIMIT 1',
				$user_id,
				$post_id
			)
		);
	}

	/**
	 * Batch bookmark lookup — which of $post_ids has $user_id bookmarked?
	 *
	 * One query for a whole page of posts, so list/feed enrichment never runs
	 * a per-row is_bookmarked() (N+1). Returns only the bookmarked IDs.
	 *
	 * @since 1.6.0
	 * @param int   $user_id  Viewer.
	 * @param int[] $post_ids Candidate post IDs.
	 * @return int[] Subset of $post_ids the user has bookmarked.
	 */
	public static function bookmarked_ids( int $user_id, array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( $user_id <= 0 || empty( $post_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows         = static::db()->get_col(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT post_id FROM ' . static::table() . " WHERE user_id = %d AND post_id IN ({$placeholders})",
				$user_id,
				...$post_ids
			)
		);

		return array_map( 'intval', $rows ?: array() );
	}

	/**
	 * The WHERE shared by list_by_user() and count_by_user(), minus the
	 * `b.user_id = %d` both bind themselves.
	 *
	 * Extracted (1.8.0) because the two had drifted: the list filtered
	 * `p.status = 'publish'` and joined the posts table, while the count read
	 * the bookmarks table alone and counted every row — so a user whose
	 * bookmarked topic was later trashed saw a count that promised more than
	 * the list could ever render, and paged into a short last page. Adding the
	 * blocked-author exclusion to only one of them would have deepened exactly
	 * that split, so they now derive the same predicate from one place.
	 *
	 * @param int $user_id Bookmark owner — also the viewer, since a bookmark
	 *                     list is only ever shown to the person who saved it.
	 * @return array{0:string,1:array} [SQL fragment, bind values]
	 */
	private static function visible_sql( int $user_id ): array {
		$sql = " AND p.status = 'publish'";

		// Blocked-author exclusion. A bookmark can PREDATE a block — you saved
		// the topic, then blocked its author — so this list reaches rows the
		// author-filtered feeds never show, and it did so with title and body
		// fully intact. Dropped rather than tombstoned to match every other
		// list surface; unblocking brings the bookmark back, since the block
		// filters the read and never touches the saved row.
		[ $block_sql, $block_vals ] = BlockedUser::exclusion_sql( $user_id, 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			return [ $sql . ' AND ' . $block_sql, $block_vals ];
		}

		return [ $sql, [] ];
	}

	/**
	 * List bookmarked posts for a user with post data.
	 *
	 * @return object[]
	 */
	public static function list_by_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$b = static::table();
		$p = table( 'posts' );

		[ $vis_sql, $vis_vals ] = self::visible_sql( $user_id );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $vis_sql inlines absint'd ids; it only adds a %d (spread via $vis_vals) past the 500-block cap.
				"SELECT p.*, b.created_at AS bookmarked_at FROM {$b} b JOIN {$p} p ON p.id = b.post_id WHERE b.user_id = %d{$vis_sql} ORDER BY b.created_at DESC LIMIT %d OFFSET %d",
				...array_merge( [ $user_id ], $vis_vals, [ $limit, $offset ] )
			)
		) ?: [];
	}

	public static function count_by_user( int $user_id ): int {
		$b = static::table();
		$p = table( 'posts' );

		[ $vis_sql, $vis_vals ] = self::visible_sql( $user_id );

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see list_by_user().
				"SELECT COUNT(*) FROM {$b} b JOIN {$p} p ON p.id = b.post_id WHERE b.user_id = %d{$vis_sql}",
				...array_merge( [ $user_id ], $vis_vals )
			)
		);
	}

	public static function remove( int $user_id, int $post_id ): bool {
		return false !== static::db()->delete(
			static::table(),
			[
				'user_id' => $user_id,
				'post_id' => $post_id,
			]
		);
	}
}
