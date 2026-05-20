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

		$id = static::insert( $data );

		// Keep the post's denormalised open-flag counter in step (post targets,
		// pending only). Caller-agnostic so REST, Abilities, and CLI all maintain it.
		if ( $id && 'post' === ( $data['object_type'] ?? 'post' ) && 'pending' === ( $data['status'] ?? 'pending' ) ) {
			Post::increment_flag_count( (int) $data['object_id'], 1 );
		}

		return $id;
	}

	/**
	 * List all flags with status 'pending', newest first.
	 *
	 * @param int $limit  Max rows to return. 0 = unbounded (default,
	 *                    preserves pre-1.4.3 behaviour).
	 * @param int $offset Row offset for pagination. Ignored when $limit = 0.
	 * @return object[]
	 */
	public static function list_pending( int $limit = 0, int $offset = 0 ): array {
		$base = 'SELECT * FROM ' . static::table() . " WHERE status = 'pending' ORDER BY created_at DESC";
		if ( $limit > 0 ) {
			return static::db()->get_results(
				static::db()->prepare( $base . ' LIMIT %d OFFSET %d', $limit, max( 0, $offset ) )
			) ?: [];
		}
		return static::db()->get_results( $base ) ?: [];
	}

	/**
	 * Count flags with status 'pending'. Cheap alternative to count()
	 * on the full row set — adopted by callers that only need the
	 * number (pagination totals, badges).
	 *
	 * @return int
	 */
	public static function count_pending(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'pending'"
		);
	}

	/**
	 * Count pending flags filed against one object (post or reply). Lets the
	 * read surfaces show moderators a "this has N open reports" indicator
	 * without loading the flag rows.
	 *
	 * @param string $object_type 'post' or 'reply'.
	 * @param int    $object_id   Target object ID.
	 * @return int
	 */
	public static function count_pending_for_object( string $object_type, int $object_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'pending' AND object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			)
		);
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
		$flag = static::find( $id );

		$ok = static::update(
			$id,
			[
				'status'      => $status,
				'resolved_by' => $resolved_by,
				'resolved_at' => now(),
			]
		);

		// A pending post-flag becoming resolved drops the post's open-flag count.
		if ( $ok && $flag && 'pending' === $flag->status && 'post' === $flag->object_type ) {
			Post::increment_flag_count( (int) $flag->object_id, -1 );
		}

		return $ok;
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
	public static function list_pending_in_space( int $space_id, int $limit = 0, int $offset = 0 ): array {
		if ( $space_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$flags_t   = \Jetonomy\table( 'flags' );
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );

		$base = "SELECT f.* FROM {$flags_t} f
			 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
			 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
			 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
			 WHERE f.status = 'pending'
			   AND (
			     ( f.object_type = 'post'  AND p.space_id  = %d )
			     OR ( f.object_type = 'reply' AND rp.space_id = %d )
			   )
			 ORDER BY f.created_at DESC";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $limit > 0 ) {
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare( $base . ' LIMIT %d OFFSET %d', $space_id, $space_id, $limit, max( 0, $offset ) )
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( $base, $space_id, $space_id )
			);
		}

		return $rows ?: [];
	}

	/**
	 * Count pending flags scoped to a single space.
	 *
	 * @param int $space_id
	 * @return int
	 */
	public static function count_pending_in_space( int $space_id ): int {
		if ( $space_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		$flags_t   = \Jetonomy\table( 'flags' );
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 WHERE f.status = 'pending'
				   AND (
				     ( f.object_type = 'post'  AND p.space_id  = %d )
				     OR ( f.object_type = 'reply' AND rp.space_id = %d )
				   )",
				$space_id,
				$space_id
			)
		);
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
	public static function list_pending_in_spaces( array $space_ids, int $limit = 0, int $offset = 0 ): array {
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

		$sql = "SELECT f.* FROM {$flags_t} f
			 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
			 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
			 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
			 WHERE f.status = 'pending'
			   AND (
			     ( f.object_type = 'post'  AND p.space_id  IN ({$placeholders}) )
			     OR ( f.object_type = 'reply' AND rp.space_id IN ({$placeholders}) )
			   )
			 ORDER BY f.created_at DESC";

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

		return $rows ?: [];
	}

	/**
	 * Count pending flags across the given set of spaces. Paired with
	 * list_pending_in_spaces() for pagination totals.
	 *
	 * @param int[] $space_ids
	 * @return int
	 */
	public static function count_pending_in_spaces( array $space_ids ): int {
		$space_ids = array_values( array_unique( array_filter( array_map( 'intval', $space_ids ) ) ) );
		if ( empty( $space_ids ) ) {
			return 0;
		}
		global $wpdb;
		$flags_t      = \Jetonomy\table( 'flags' );
		$posts_t      = \Jetonomy\table( 'posts' );
		$replies_t    = \Jetonomy\table( 'replies' );
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		$params       = array_merge( $space_ids, $space_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 WHERE f.status = 'pending'
				   AND (
				     ( f.object_type = 'post'  AND p.space_id  IN ({$placeholders}) )
				     OR ( f.object_type = 'reply' AND rp.space_id IN ({$placeholders}) )
				   )",
				...$params
			)
		);
	}
}
