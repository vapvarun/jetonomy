<?php
/**
 * Tag model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use function Jetonomy\table;

class Tag extends Model {

	protected static function table_name(): string {
		return 'tags';
	}

	/**
	 * Find a tag by slug, creating it if it does not exist.
	 *
	 * @param string $name Tag display name. Slug is derived automatically.
	 * @return int Tag ID.
	 */
	public static function find_or_create( string $name ): int {
		$slug     = sanitize_title( $name );
		$existing = static::find_by_slug( $slug );

		if ( $existing ) {
			return (int) $existing->id;
		}

		return static::insert(
			[
				'name' => $name,
				'slug' => $slug,
			]
		);
	}

	/**
	 * Find a tag by its slug.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ): ?object {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s',
				$slug
			)
		);
		return $row ?: null;
	}

	/**
	 * Attach a tag to a post. Silently ignores duplicate entries.
	 *
	 * Also increments the tag's post_count.
	 *
	 * @param int $post_id
	 * @param int $tag_id
	 */
	public static function attach_to_post( int $post_id, int $tag_id ): void {
		$post_tags = table( 'post_tags' );
		$affected  = static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$post_tags} (post_id, tag_id) VALUES (%d, %d)",
				$post_id,
				$tag_id
			)
		);

		if ( $affected ) {
			static::db()->query(
				static::db()->prepare(
					'UPDATE ' . static::table() . ' SET post_count = post_count + 1 WHERE id = %d',
					$tag_id
				)
			);
		}
	}

	/**
	 * Detach a tag from a post.
	 *
	 * Also decrements the tag's post_count.
	 *
	 * @param int $post_id
	 * @param int $tag_id
	 */
	public static function detach_from_post( int $post_id, int $tag_id ): void {
		$post_tags = table( 'post_tags' );
		$affected  = static::db()->delete(
			$post_tags,
			[
				'post_id' => $post_id,
				'tag_id'  => $tag_id,
			]
		);

		if ( $affected ) {
			static::db()->query(
				static::db()->prepare(
					'UPDATE ' . static::table() . ' SET post_count = GREATEST(post_count - 1, 0) WHERE id = %d',
					$tag_id
				)
			);
		}
	}

	/**
	 * List all tags attached to a post.
	 *
	 * @param int $post_id
	 * @return object[]
	 */
	public static function list_for_post( int $post_id ): array {
		$post_tags = table( 'post_tags' );
		$tags      = static::table();

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT t.* FROM {$tags} t INNER JOIN {$post_tags} pt ON pt.tag_id = t.id WHERE pt.post_id = %d ORDER BY t.name ASC",
				$post_id
			)
		) ?: [];
	}

	/**
	 * List the most popular tags by post_count.
	 *
	 * @param int $limit Maximum number of tags to return.
	 * @return object[]
	 */
	public static function list_popular( int $limit = 20 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' ORDER BY post_count DESC LIMIT %d',
				$limit
			)
		) ?: [];
	}

	/**
	 * Check whether a tag with the given slug exists.
	 *
	 * @param string $slug Tag slug.
	 * @return bool
	 */
	public static function exists( string $slug ): bool {
		return null !== static::find_by_slug( $slug );
	}

	/**
	 * List posts (discussions) that have a given tag, ordered by recency.
	 *
	 * Returns rows from jt_posts joined through jt_post_tags.
	 * Each row includes: id, title, slug, space_id, author_id, reply_count,
	 * vote_score, created_at, plus the author's display_name.
	 *
	 * @param string $slug  Tag slug.
	 * @param int    $limit Maximum number of posts to return.
	 * @return object[]
	 */
	public static function list_by_tag( string $slug, int $limit = 5 ): array {
		$tags      = static::table();
		$post_tags = table( 'post_tags' );
		$posts     = table( 'posts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.id, p.title, p.slug AS post_slug, p.space_id, p.author_id,
				        p.reply_count, p.vote_score, p.created_at,
				        u.display_name AS author_name
				 FROM {$posts} p
				 INNER JOIN {$post_tags} pt ON pt.post_id = p.id
				 INNER JOIN {$tags} t ON t.id = pt.tag_id
				 INNER JOIN {$GLOBALS['wpdb']->users} u ON u.ID = p.author_id
				 WHERE t.slug = %s
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$slug,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $results ?: [];
	}

	/**
	 * Search tags by name using a partial-match query.
	 *
	 * @param string $query Search string matched against the name column.
	 * @param int    $limit Maximum number of tags to return.
	 * @return object[]
	 */
	public static function search( string $query, int $limit = 20 ): array {
		$like = '%' . static::db()->esc_like( $query ) . '%';
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE name LIKE %s ORDER BY name ASC LIMIT %d',
				$like,
				$limit
			)
		) ?: [];
	}

	/**
	 * Paginated list for the admin table. Supports search, sort, and limit/offset.
	 *
	 * @param string $search   Optional LIKE filter against name.
	 * @param string $orderby  One of: id, name, slug, post_count, created_at. Falls back to name.
	 * @param string $order    ASC or DESC.
	 * @param int    $per_page Rows per page (capped 1..100).
	 * @param int    $offset   SQL offset.
	 * @return array{rows: object[], total: int}
	 */
	public static function list_paginated( string $search = '', string $orderby = 'name', string $order = 'ASC', int $per_page = 20, int $offset = 0 ): array {
		$allowed_orderby = [ 'id', 'name', 'slug', 'post_count', 'created_at' ];
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'name';
		$order           = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';
		$per_page        = max( 1, min( 100, $per_page ) );
		$offset          = max( 0, $offset );

		$where   = '';
		$values  = [];
		if ( '' !== $search ) {
			$where    = 'WHERE name LIKE %s';
			$values[] = '%' . static::db()->esc_like( $search ) . '%';
		}

		$table = static::table();

		// Count query.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = empty( $values ) ? (int) static::db()->get_var( $count_sql ) : (int) static::db()->get_var( static::db()->prepare( $count_sql, ...$values ) );

		// Data query. $orderby / $order / $per_page / $offset are sanitized above.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data_sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$args     = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = static::db()->get_results( static::db()->prepare( $data_sql, ...$args ) ) ?: [];

		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * Delete a tag and detach it from every post. Also removes space tag links.
	 *
	 * @param int $id Tag ID.
	 * @return bool True if the tag row was removed.
	 */
	public static function delete_with_relations( int $id ): bool {
		$post_tags     = table( 'post_tags' );
		$space_tag_map = table( 'space_tag_map' );
		$db            = static::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$db->delete( $post_tags, [ 'tag_id' => $id ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$db->delete( $space_tag_map, [ 'tag_id' => $id ] );

		$deleted = (bool) parent::delete( $id );
		return $deleted;
	}
}
