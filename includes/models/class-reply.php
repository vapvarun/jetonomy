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
	 * List published replies for a post with sorting options.
	 *
	 * Sort options:
	 *   'oldest' — created_at ASC (default)
	 *   'newest' — created_at DESC
	 *   'best'   — vote_score DESC
	 *
	 * @param int    $post_id
	 * @param string $sort
	 * @param int    $limit
	 * @param int    $offset
	 * @return object[]
	 */
	public static function list_by_post( int $post_id, string $sort = 'oldest', int $limit = 30, int $offset = 0 ): array {
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
}
