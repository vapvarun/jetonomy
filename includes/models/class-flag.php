<?php
/**
 * Flag model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Flag extends Model {

	protected static function table_name(): string {
		return 'flags';
	}

	/**
	 * Create a new flag report.
	 *
	 * Automatically sets status to 'pending' and created_at if absent.
	 *
	 * @param array $data Column data (object_type, object_id, reporter_id, reason, etc.).
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'status'     => 'pending',
				'created_at' => now(),
			],
			$data
		);

		return static::insert( $data );
	}

	/**
	 * List all flags with status 'pending', newest first.
	 *
	 * @return object[]
	 */
	public static function list_pending(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE status = 'pending' ORDER BY created_at DESC"
		) ?: [];
	}

	/**
	 * Resolve a flag (approve/dismiss) and record who resolved it.
	 *
	 * @param int    $id          Flag row ID.
	 * @param int    $resolved_by User ID of the moderator resolving the flag.
	 * @param string $status      New status value (e.g. 'approved', 'dismissed').
	 * @return bool True on success.
	 */
	public static function resolve( int $id, int $resolved_by, string $status ): bool {
		return static::update(
			$id,
			[
				'status'      => $status,
				'resolved_by' => $resolved_by,
				'resolved_at' => now(),
			]
		);
	}

	/**
	 * List flags filtered by status, newest first.
	 *
	 * @param string $status Row status value to filter by (e.g. 'pending', 'approved', 'dismissed').
	 * @param int    $limit  Maximum number of rows to return.
	 * @return object[]
	 */
	public static function list_by_status( string $status = 'pending', int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE status = %s ORDER BY created_at DESC LIMIT %d',
				$status,
				$limit
			)
		) ?: [];
	}

	/**
	 * Find an existing flag by reporter and object (any status).
	 *
	 * @param int    $reporter_id Reporter user ID.
	 * @param string $object_type Object type (post, reply).
	 * @param int    $object_id   Object row ID.
	 * @return object|null Flag row or null.
	 */
	public static function find_by_reporter_and_object( int $reporter_id, string $object_type, int $object_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE reporter_id = %d AND object_type = %s AND object_id = %d LIMIT 1',
				$reporter_id,
				$object_type,
				$object_id
			)
		) ?: null;
	}

	/**
	 * List all flags filed against a single post (any status), newest first.
	 *
	 * Used by `GET /posts/{id}/flags` (1.4.1 A5) so a moderator viewing a
	 * specific post can see its flags without filtering the global queue.
	 * Row shape matches `list_pending()` so frontend can swap data sources
	 * without remapping fields.
	 *
	 * @param int $post_id Post row ID.
	 * @return object[]
	 */
	public static function find_for_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE object_type = 'post' AND object_id = %d ORDER BY created_at DESC",
				$post_id
			)
		) ?: [];
	}

	/**
	 * Count how many flags exist for a given object (regardless of status).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return int
	 */
	public static function count_for_object( string $object_type, int $object_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				$object_id
			)
		);
	}

	/**
	 * Resolve every other pending flag filed against the same object.
	 *
	 * Called after a moderator marks one flag as 'valid' and the underlying
	 * post or reply is trashed. Without this cascade, the queue keeps
	 * showing stale pending flags pointing at removed content, and a second
	 * moderator can re-action the same object minutes later.
	 *
	 * Excludes the originating flag (already resolved by the caller).
	 *
	 * @param string $object_type     'post' or 'reply'.
	 * @param int    $object_id       Object row ID.
	 * @param int    $resolved_by     Moderator user ID applying the cascade.
	 * @param string $status          New status ('valid' mirrors the originator).
	 * @param int    $exclude_flag_id Flag ID already resolved by the caller.
	 * @return int Number of sibling flags transitioned.
	 */
	public static function resolve_siblings_for(
		string $object_type,
		int $object_id,
		int $resolved_by,
		string $status,
		int $exclude_flag_id
	): int {
		if ( $object_id <= 0 || ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			return 0;
		}

		$db = static::db();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from static::table()
		$rows = $db->query(
			$db->prepare(
				'UPDATE ' . static::table() . " SET status = %s, resolved_by = %d, resolved_at = %s WHERE object_type = %s AND object_id = %d AND status = 'pending' AND id != %d",
				$status,
				$resolved_by,
				now(),
				$object_type,
				$object_id,
				$exclude_flag_id
			)
		);

		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * List pending flags on content that belongs to a single space.
	 *
	 * Resolves each flag's owning space via a LEFT JOIN chain:
	 *   post  flag → jt_posts
	 *   reply flag → jt_replies → jt_posts
	 * User flags are excluded — they have no space scope.
	 *
	 * @param int $space_id
	 * @return object[]
	 */
	public static function list_pending_in_space( int $space_id ): array {
		if ( $space_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$flags_t   = \Jetonomy\table( 'flags' );
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.* FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 WHERE f.status = 'pending'
				   AND (
				     ( f.object_type = 'post'  AND p.space_id  = %d )
				     OR ( f.object_type = 'reply' AND rp.space_id = %d )
				   )
				 ORDER BY f.created_at DESC",
				$space_id,
				$space_id
			)
		);

		return $rows ?: [];
	}

	/**
	 * List pending flags on content across a set of spaces.
	 *
	 * Used by the scoped aggregate view (space mod visiting the admin-style
	 * queue without global cap).
	 *
	 * @param int[] $space_ids
	 * @return object[]
	 */
	public static function list_pending_in_spaces( array $space_ids ): array {
		$space_ids = array_values( array_unique( array_filter( array_map( 'intval', $space_ids ) ) ) );
		if ( empty( $space_ids ) ) {
			return [];
		}

		global $wpdb;
		$flags_t      = \Jetonomy\table( 'flags' );
		$posts_t      = \Jetonomy\table( 'posts' );
		$replies_t    = \Jetonomy\table( 'replies' );
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		$params       = array_merge( $space_ids, $space_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				"SELECT f.* FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 WHERE f.status = 'pending'
				   AND (
				     ( f.object_type = 'post'  AND p.space_id  IN ({$placeholders}) )
				     OR ( f.object_type = 'reply' AND rp.space_id IN ({$placeholders}) )
				   )
				 ORDER BY f.created_at DESC",
				...$params
			)
		);

		return $rows ?: [];
	}
}
