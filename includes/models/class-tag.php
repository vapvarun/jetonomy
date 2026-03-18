<?php
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
}
