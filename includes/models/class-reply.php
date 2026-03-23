<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Reply extends Model {

	protected static function table_name(): string {
		return 'replies';
	}

	/**
	 * Create a new reply.
	 *
	 * Sets created_at and status defaults if absent. After a successful insert,
	 * increments the parent post's reply_count.
	 *
	 * @param array $data Column data.
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'status'     => 'publish',
				'created_at' => now(),
			],
			$data
		);

		$id = static::insert( $data );

		if ( $id > 0 && ! empty( $data['post_id'] ) ) {
			Post::increment_reply_count( (int) $data['post_id'] );
		}

		return $id;
	}

	/**
	 * List published replies for a post with sorting and cursor-based pagination.
	 *
	 * Sort options:
	 *   'oldest' — created_at ASC (default)
	 *   'newest' — created_at DESC
	 *   'best'   — vote_score DESC
	 *
	 * Cursor param:
	 *   $after > 0  — return only rows with id > $after (forward cursor)
	 *
	 * @param int    $post_id
	 * @param string $sort
	 * @param int    $limit
	 * @param int    $offset  Legacy offset; ignored when $after > 0.
	 * @param int    $after   Cursor: return replies after this reply ID.
	 * @return object[]
	 */
	public static function list_by_post( int $post_id, string $sort = 'oldest', int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			$settings = get_option( 'jetonomy_settings', [] );
			$limit    = (int) ( $settings['replies_per_page'] ?? 30 );
		}
		$table = static::table();

		switch ( $sort ) {
			case 'newest':
				$order_by = 'created_at DESC';
				break;

			case 'best':
				$order_by = 'vote_score DESC';
				break;

			case 'oldest':
			default:
				$order_by = 'created_at ASC';
				break;
		}

		// Cursor: prefer id-based over offset when $after is provided.
		if ( $after > 0 ) {
			return static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE post_id = %d AND status = 'publish' AND id > %d ORDER BY {$order_by} LIMIT %d",
					$post_id,
					$after,
					$limit
				)
			) ?: [];
		}

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE post_id = %d AND status = 'publish' ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$post_id,
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Mark a reply as the accepted answer.
	 *
	 * @param int $id Reply ID.
	 */
	public static function mark_accepted( int $id ): void {
		static::update( $id, [ 'is_accepted' => 1 ] );
	}

	/**
	 * Count replies for a given post.
	 *
	 * @param int $post_id
	 * @return int
	 */
	public static function count_by_post( int $post_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE post_id = %d',
				$post_id
			)
		);
	}

	/**
	 * Get replies as a threaded tree.
	 *
	 * Returns top-level replies with nested 'children' arrays.
	 * Max depth: 3 levels.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $sort    Sort order: 'oldest', 'newest', or 'best'.
	 * @param int    $limit   Max top-level replies to return (0 = all).
	 * @param int    $offset  Offset for top-level replies.
	 * @return array Threaded reply tree.
	 */
	public static function get_threaded( int $post_id, string $sort = 'oldest', int $limit = 0, int $offset = 0 ): array {
		// Fetch ALL replies for this post (we need full tree to build hierarchy).
		$all = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM " . static::table() . " WHERE post_id = %d AND status = 'publish' ORDER BY created_at ASC",
				$post_id
			)
		);

		if ( empty( $all ) ) {
			return [];
		}

		// Build tree: group by parent_id.
		$by_parent = [];
		foreach ( $all as $reply ) {
			$pid = (int) ( $reply->parent_id ?? 0 );
			$by_parent[ $pid ][] = $reply;
		}

		// Recursively attach children (max 3 levels).
		$tree = self::build_tree( $by_parent, 0, 0, 3 );

		// Sort top-level based on sort param.
		if ( 'newest' === $sort ) {
			$tree = array_reverse( $tree );
		} elseif ( 'best' === $sort ) {
			usort( $tree, fn( $a, $b ) => (int) $b->vote_score - (int) $a->vote_score );
		}

		// Apply offset/limit to top-level replies only.
		if ( $limit > 0 ) {
			$tree = array_slice( $tree, $offset, $limit );
		}

		return $tree;
	}

	/**
	 * Recursively build threaded tree from grouped replies.
	 *
	 * @param array $by_parent Replies grouped by parent_id.
	 * @param int   $parent_id Current parent ID.
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Maximum nesting depth.
	 * @return array Nested reply nodes.
	 */
	private static function build_tree( array &$by_parent, int $parent_id, int $depth, int $max_depth ): array {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return [];
		}

		$nodes = [];
		foreach ( $by_parent[ $parent_id ] as $reply ) {
			$reply->depth    = $depth;
			$reply->children = [];

			if ( $depth < $max_depth ) {
				$reply->children = self::build_tree( $by_parent, (int) $reply->id, $depth + 1, $max_depth );
			} else {
				// At max depth, flatten further children at the same level.
				$reply->children = self::build_tree( $by_parent, (int) $reply->id, $depth, $max_depth );
			}

			$nodes[] = $reply;
		}

		return $nodes;
	}

	/**
	 * Count top-level replies (parent_id IS NULL or 0).
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function count_top_level( int $post_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				"SELECT COUNT(*) FROM " . static::table() . " WHERE post_id = %d AND (parent_id IS NULL OR parent_id = 0) AND status = 'publish'",
				$post_id
			)
		);
	}
}
