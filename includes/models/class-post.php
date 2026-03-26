<?php
/**
 * Post model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

/**
 * Represents a forum post (topic, question, idea, etc.).
 */
class Post extends Model {

	/**
	 * Get the table name suffix.
	 *
	 * @return string
	 */
	protected static function table_name(): string {
		return 'posts';
	}

	/**
	 * Create a new post.
	 *
	 * Sets created_at, updated_at, and status defaults if absent. Generates a
	 * slug from the title when one is not provided. After a successful insert,
	 * increments the parent space's post_count.
	 *
	 * @param array $data Column data.
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$now  = now();
		$data = array_merge(
			array(
				'status'     => 'publish',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);

		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = sanitize_title( $data['title'] );
		}

		$id = static::insert( $data );

		if ( $id > 0 ) {
			// Only increment counters for published posts (not drafts/scheduled).
			if ( 'publish' === ( $data['status'] ?? 'publish' ) ) {
				if ( ! empty( $data['space_id'] ) ) {
					Space::increment_post_count( (int) $data['space_id'] );
				}
				if ( ! empty( $data['author_id'] ) ) {
					UserProfile::increment_post_count( (int) $data['author_id'] );
				}
			}
		}

		return $id;
	}

	/**
	 * Find a post by its slug.
	 *
	 * @param string $slug Post slug.
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ): ?object {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s',
				$slug
			)
		);
		return $row ? $row : null;
	}

	/**
	 * List published posts in a space with sorting and cursor-based pagination.
	 *
	 * Sort options:
	 *   'latest'      — sticky posts first, then last_reply_at DESC
	 *   'popular'     — vote_score DESC
	 *   'unanswered'  — reply_count = 0, created_at DESC
	 *
	 * Cursor param:
	 *   $after > 0  — return only rows with id > $after (forward cursor)
	 *
	 * @param int    $space_id Space ID.
	 * @param string $sort     Sort order.
	 * @param int    $limit    Max rows.
	 * @param int    $offset   Legacy offset; ignored when $after > 0.
	 * @param int    $after   Cursor: return items after this post ID.
	 * @return object[]
	 */
	public static function list_by_space( int $space_id, string $sort = 'latest', int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			// Per-space override, then global fallback.
			$space_settings = Space::get_settings( $space_id );
			$limit          = ! empty( $space_settings['posts_per_page'] ) ? (int) $space_settings['posts_per_page'] : 0;
			if ( $limit <= 0 ) {
				$global = get_option( 'jetonomy_settings', array() );
				$limit  = (int) ( $global['posts_per_page'] ?? 20 );
			}
		}
		$table = static::table();

		$extra_where = '';

		switch ( $sort ) {
			case 'popular':
				$order_by = 'vote_score DESC';
				break;

			case 'unanswered':
				$order_by    = 'created_at DESC';
				$extra_where = ' AND reply_count = 0';
				break;

			case 'latest':
			default:
				$order_by = 'is_sticky DESC, last_reply_at DESC';
				break;
		}

		// Cursor: prefer id-based over offset when $after is provided.
		if ( $after > 0 ) {
			$params  = array( $space_id, $after, $limit );
			$results = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} AND id > %d ORDER BY {$order_by} LIMIT %d",
					...$params
				)
			);
			return $results ? $results : array();
		}

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$space_id,
				$limit,
				$offset
			)
		);
		return $results ? $results : array();
	}

	/**
	 * Adjust reply_count and update last_reply_at and updated_at.
	 *
	 * Pass a negative value to decrement. Uses GREATEST() to prevent
	 * the counter from going below zero.
	 *
	 * @param int $id Post ID.
	 * @param int $by Amount to adjust (default +1).
	 */
	public static function increment_reply_count( int $id, int $by = 1 ): void {
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET reply_count = GREATEST(reply_count + %d, 0), last_reply_at = %s, updated_at = %s WHERE id = %d',
				$by,
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * Increment view_count by 1.
	 *
	 * @param int $id Post ID.
	 */
	public static function increment_view_count( int $id ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET view_count = view_count + 1 WHERE id = %d',
				$id
			)
		);
	}

	/**
	 * Close a post (prevent new replies).
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function close( int $id ): bool {
		return static::update( $id, array( 'is_closed' => 1 ) );
	}

	/**
	 * Pin (sticky) a post so it appears at the top of listings.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function pin( int $id ): bool {
		return static::update( $id, array( 'is_sticky' => 1 ) );
	}

	/**
	 * Mark a reply as the accepted answer and resolve the post.
	 *
	 * @param int $id       Post ID.
	 * @param int $reply_id Reply ID to accept.
	 * @return bool
	 */
	public static function accept_reply( int $id, int $reply_id ): bool {
		return static::update(
			$id,
			array(
				'accepted_reply_id' => $reply_id,
				'is_resolved'       => 1,
			)
		);
	}

	/**
	 * Publish a scheduled post when its published_at time has arrived.
	 *
	 * @param int $id Post ID.
	 * @return bool True if published.
	 */
	public static function publish_scheduled( int $id ): bool {
		$post = static::find( $id );
		if ( ! $post || 'draft' !== ( $post->status ?? '' ) || empty( $post->published_at ) ) {
			return false;
		}

		static::update(
			$id,
			array(
				'status'     => 'publish',
				'updated_at' => now(),
			)
		);

		// Increment counters now that it's published.
		Space::increment_post_count( (int) $post->space_id );
		UserProfile::increment_post_count( (int) $post->author_id );

		do_action( 'jetonomy_scheduled_post_published', $id, (int) $post->space_id );

		return true;
	}

	/**
	 * List draft posts by a specific user.
	 *
	 * @param int $user_id Author user ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Pagination offset.
	 * @return object[]
	 */
	public static function list_drafts_by_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$table      = static::table();
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.*, s.slug AS space_slug, s.title AS space_title
				 FROM {$table} p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'draft'
				 ORDER BY p.created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $results ? $results : array();
	}

	/**
	 * Get posts that are scheduled and due for publishing.
	 *
	 * @return object[]
	 */
	public static function get_due_scheduled(): array {
		$table = static::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status = 'draft' AND published_at IS NOT NULL AND published_at <= %s",
				\Jetonomy\now()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $results ? $results : array();
	}

	/**
	 * Merge a source post into a target post.
	 *
	 * Moves all replies from the source post to the target, recalculates
	 * reply counts, and trashes the source post.
	 *
	 * @param int $source_id Source post ID (will be trashed).
	 * @param int $target_id Target post ID (receives replies).
	 * @return bool True on success.
	 */
	public static function merge_into( int $source_id, int $target_id ): bool {
		$source = static::find( $source_id );
		$target = static::find( $target_id );

		if ( ! $source || ! $target ) {
			return false;
		}

		$replies_table = \Jetonomy\table( 'replies' );

		// Move all replies from source to target.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$replies_table} SET post_id = %d WHERE post_id = %d",
				$target_id,
				$source_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Recalculate reply count on target.
		$new_count = Reply::count_by_post( $target_id );
		static::update(
			$target_id,
			array(
				'reply_count' => $new_count,
				'updated_at'  => now(),
			)
		);

		// Trash the source post and decrement its space counter.
		static::update( $source_id, array( 'status' => 'trash' ) );
		Space::increment_post_count( (int) $source->space_id, -1 );

		do_action( 'jetonomy_post_merged', $source_id, $target_id );

		return true;
	}
}
