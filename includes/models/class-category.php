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
	 * Visibility predicate for category listings.
	 *
	 * `jt_categories.visibility` was written by the admin UI from the start but
	 * read by nothing: no listing, no lookup and no REST response filtered on
	 * it, so a category the owner marked `hidden` was served to anonymous
	 * visitors on the directory AND by `GET /categories`, which additionally
	 * disclosed the `visibility` field itself. The route's `permission_callback`
	 * (`Visibility::rest_check`) could not help — it is a global "is this
	 * community public" gate, not a per-row filter, and says so.
	 *
	 * Deliberately mirrors {@see Space::listing_visibility_sql()}, including its
	 * semantics: `private` stays discoverable by design, `hidden` is the state
	 * that conceals. Categories have no membership table, so there is no
	 * per-member branch — the member case differs from the guest case only in
	 * seeing `private`.
	 *
	 * Owners keep full sight of their own categories: anyone who can manage
	 * them gets `1=1`, so the admin screens are unaffected by this filter.
	 *
	 * @param int|null $user_id Viewer ID (null resolves to the current user, 0 for guests).
	 * @param string   $alias   Categories-table alias without trailing dot.
	 * @return array{0:string,1:array} [ SQL fragment, bind values ].
	 */
	public static function listing_visibility_sql( ?int $user_id = null, string $alias = '' ): array {
		$user_id = $user_id ?? get_current_user_id();
		$col     = '' !== $alias ? $alias . '.' : '';

		if ( $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'jetonomy_manage_categories' ) ) ) {
			$result = [ '1=1', [] ];
		} elseif ( $user_id <= 0 ) {
			$result = [ "{$col}visibility = 'public'", [] ];
		} else {
			$result = [ "{$col}visibility IN ('public','private')", [] ];
		}

		/**
		 * Filter the category-listing visibility SQL predicate.
		 *
		 * @param array{0:string,1:array} $result  [ SQL fragment, bind values ].
		 * @param int                     $user_id Viewer ID (resolved; 0 for guest).
		 * @param string                  $alias   Categories-table alias without trailing dot.
		 */
		return apply_filters( 'jetonomy_category_listing_visibility_sql', $result, $user_id, $alias );
	}

	/**
	 * Find a category by its slug.
	 *
	 * Visibility-filtered: a `hidden` category is not resolvable by slug for a
	 * viewer who may not see it, so direct-URL access cannot bypass the
	 * listings the same predicate governs.
	 *
	 * @param string   $slug
	 * @param int|null $user_id Viewer ID (null resolves to the current user).
	 * @return object|null
	 */
	public static function find_by_slug( string $slug, ?int $user_id = null ): ?object {
		[ $vis_where, $vis_values ] = self::listing_visibility_sql( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from listing_visibility_sql() with literal SQL only.
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE slug = %s AND {$vis_where}",
				$slug,
				...$vis_values
			)
		);
		return $row ?: null;
	}

	/**
	 * Find a category by id, but only if the viewer may see it.
	 *
	 * The id twin of {@see self::find_by_slug()}. Model::find() is the raw
	 * fetch and stays unfiltered because internal callers (breadcrumbs on a
	 * space the viewer already reached, admin screens behind a capability
	 * check) legitimately need the row. Anything answering an untrusted id -
	 * REST, a shortcode attribute - asks this instead. Without it, GET
	 * /categories/{id} answered 200 with a `hidden` category's name to a guest
	 * while the collection beside it correctly withheld the same row.
	 *
	 * @param int      $id      Category id.
	 * @param int|null $user_id Viewer ID (null resolves to the current user).
	 * @return object|null
	 */
	public static function find_visible( int $id, ?int $user_id = null ): ?object {
		if ( $id <= 0 ) {
			return null;
		}

		[ $vis_where, $vis_values ] = self::listing_visibility_sql( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from listing_visibility_sql() with literal SQL only.
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE id = %d AND {$vis_where}",
				$id,
				...$vis_values
			)
		);
		return $row ?: null;
	}

	/**
	 * List all top-level categories (parent_id IS NULL or 0).
	 *
	 * @return object[]
	 */
	public static function list_top_level( ?int $user_id = null ): array {
		[ $vis_where, $vis_values ] = self::listing_visibility_sql( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from listing_visibility_sql() with literal SQL only.
		$sql = 'SELECT * FROM ' . static::table() . " WHERE (parent_id IS NULL OR parent_id = 0) AND {$vis_where} ORDER BY sort_order ASC, name ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return static::db()->get_results(
			empty( $vis_values ) ? $sql : static::db()->prepare( $sql, ...$vis_values )
		) ?: [];
	}

	/**
	 * List child categories for a given parent.
	 *
	 * @param int $parent_id
	 * @return object[]
	 */
	public static function list_children( int $parent_id, ?int $user_id = null ): array {
		[ $vis_where, $vis_values ] = self::listing_visibility_sql( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from listing_visibility_sql() with literal SQL only.
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE parent_id = %d AND {$vis_where} ORDER BY sort_order ASC, name ASC",
				$parent_id,
				...$vis_values
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

		[ $vis_where, $vis_values ] = self::listing_visibility_sql();

		// Anyone who can reach this screen can manage categories, so the
		// predicate resolves to 1=1 for them and nothing changes here. It is
		// applied anyway so there is exactly one rule for reading this table
		// rather than one rule plus a remembered exception.
		$where  = "WHERE (parent_id IS NULL OR parent_id = 0) AND {$vis_where}";
		$values = $vis_values;
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
