<?php
/**
 * Category model.
 *
 * @package Jetonomy
 */

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
	 * Paginated list of top-level categories for the admin page.
	 *
	 * @param string $search   Optional LIKE filter against name.
	 * @param string $orderby  One of: id, name, slug, sort_order. Falls back to sort_order.
	 * @param string $order    ASC|DESC.
	 * @param int    $per_page Rows per page (capped 1..100).
	 * @param int    $offset   SQL offset.
	 * @return array{rows: object[], total: int}
	 */
	public static function list_paginated( string $search = '', string $orderby = 'sort_order', string $order = 'ASC', int $per_page = 20, int $offset = 0 ): array {
		$allowed  = [ 'id', 'name', 'slug', 'sort_order' ];
		$orderby  = in_array( $orderby, $allowed, true ) ? $orderby : 'sort_order';
		$order    = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = max( 0, $offset );

		$where  = 'WHERE (parent_id IS NULL OR parent_id = 0)';
		$values = [];
		if ( '' !== $search ) {
			$where   .= ' AND name LIKE %s';
			$values[] = '%' . static::db()->esc_like( $search ) . '%';
		}

		$table     = static::table();
		$secondary = 'sort_order' === $orderby ? ', name ASC' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = empty( $values ) ? (int) static::db()->get_var( $count_sql ) : (int) static::db()->get_var( static::db()->prepare( $count_sql, ...$values ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data_sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order}{$secondary} LIMIT %d OFFSET %d";
		$args     = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = static::db()->get_results( static::db()->prepare( $data_sql, ...$args ) ) ?: [];

		// Hydrate children inline (children share parent's page; usually a small
		// number per parent, no need to paginate those).
		foreach ( $rows as $row ) {
			$row->children = self::list_children( (int) $row->id );
		}

		return [
			'rows'  => $rows,
			'total' => $total,
		];
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
