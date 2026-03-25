<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Category extends Model {

	protected static function table_name(): string {
		return 'categories';
	}

	/**
	 * Create a new category.
	 *
	 * @param array $data Column data. created_at and sort_order are set automatically if absent.
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'sort_order' => 0,
				'created_at' => now(),
			],
			$data
		);

		return static::insert( $data );
	}

	/**
	 * Find a category by its slug.
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
	 * List all top-level categories (parent_id IS NULL or 0).
	 *
	 * @return object[]
	 */
	public static function list_top_level(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . ' WHERE parent_id IS NULL OR parent_id = 0 ORDER BY sort_order ASC, name ASC'
		) ?: [];
	}

	/**
	 * List child categories for a given parent.
	 *
	 * @param int $parent_id
	 * @return object[]
	 */
	public static function list_children( int $parent_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, name ASC',
				$parent_id
			)
		) ?: [];
	}

	/**
	 * Increment (or decrement) the space_count for a category.
	 *
	 * @param int $id Category ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_space_count( int $id, int $by = 1 ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET space_count = GREATEST(space_count + %d, 0) WHERE id = %d',
				$by,
				$id
			)
		);
	}
}
