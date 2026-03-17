<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Space extends Model {

	protected static function table_name(): string {
		return 'spaces';
	}

	/**
	 * Create a new space.
	 *
	 * Sets created_at and updated_at if absent, then increments the parent
	 * category's space_count after a successful insert.
	 *
	 * @param array $data Column data.
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$now  = now();
		$data = array_merge(
			[
				'created_at' => $now,
				'updated_at' => $now,
			],
			$data
		);

		$id = static::insert( $data );

		if ( $id > 0 && ! empty( $data['category_id'] ) ) {
			Category::increment_space_count( (int) $data['category_id'] );
		}

		return $id;
	}

	/**
	 * Find a space by its slug.
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
	 * List top-level spaces in a category.
	 *
	 * @param int $category_id
	 * @return object[]
	 */
	public static function list_by_category( int $category_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE category_id = %d AND (parent_id IS NULL OR parent_id = 0) ORDER BY sort_order ASC, title ASC',
				$category_id
			)
		) ?: [];
	}

	/**
	 * List child spaces for a given parent space.
	 *
	 * @param int $parent_id
	 * @return object[]
	 */
	public static function list_children( int $parent_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, title ASC',
				$parent_id
			)
		) ?: [];
	}

	/**
	 * Increment the post_count and update activity timestamps.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_post_count( int $id, int $by = 1 ): void {
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET post_count = post_count + %d, last_activity_at = %s, updated_at = %s WHERE id = %d',
				$by,
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * Increment the member_count and update updated_at.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_member_count( int $id, int $by = 1 ): void {
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET member_count = member_count + %d, updated_at = %s WHERE id = %d',
				$by,
				$now,
				$id
			)
		);
	}

	/**
	 * Return the decoded settings array for a space.
	 *
	 * @param int $id Space ID.
	 * @return array Settings key/value pairs, or empty array if none.
	 */
	public static function get_settings( int $id ): array {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT settings FROM ' . static::table() . ' WHERE id = %d',
				$id
			)
		);

		if ( ! $row || empty( $row->settings ) ) {
			return [];
		}

		$decoded = json_decode( $row->settings, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
