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

		// Interactive callers reject a bad parent with parent_error() first;
		// this keeps machine writers (importers, seeders) inside the two-level
		// rule instead of failing a long import over one deep forum.
		if ( ! empty( $data['parent_id'] ) ) {
			$data['parent_id'] = self::top_level_ancestor( (int) $data['parent_id'] );
		}

		return static::insert( $data );
	}

	/**
	 * Update a category, refusing a parent that breaks the two-level rule.
	 *
	 * @param int   $id   Category id.
	 * @param array $data Column => value pairs.
	 * @return bool|\WP_Error
	 */
	public static function update( int $id, array $data ): bool|\WP_Error {
		if ( array_key_exists( 'parent_id', $data ) ) {
			$error = self::parent_error( $id, (int) $data['parent_id'] );
			if ( $error ) {
				return $error;
			}
		}

		return parent::update( $id, $data );
	}

	/**
	 * Delete a category only when nothing is filed under it.
	 *
	 * The one guard every caller routes through. REST used to delete a parent
	 * outright, leaving its sub-categories pointing at a missing row (gone
	 * from every listing and unrepairable in wp-admin) and its spaces filed
	 * under nothing (Basecamp 10355160875). Active spaces and sub-categories
	 * block the delete; archived spaces are moved to uncategorised so a later
	 * restore does not bring them back inside a category that no longer exists.
	 *
	 * @param int $id Category id.
	 * @return bool|\WP_Error 409 WP_Error while spaces or sub-categories remain.
	 */
	public static function delete( int $id ): bool|\WP_Error {
		if ( Space::count(
			[
				'category_id' => $id,
				'status'      => 'active',
			]
		) > 0 ) {
			return new \WP_Error( 'jetonomy_category_has_spaces', __( 'Cannot delete a category that contains spaces. Move or delete the spaces first.', 'jetonomy' ), [ 'status' => 409 ] );
		}

		if ( static::count( [ 'parent_id' => $id ] ) > 0 ) {
			return new \WP_Error( 'jetonomy_category_has_children', __( 'Cannot delete a category that has sub-categories. Delete them first.', 'jetonomy' ), [ 'status' => 409 ] );
		}

		$parked = static::db()->get_col(
			static::db()->prepare( 'SELECT id FROM ' . \Jetonomy\table( 'spaces' ) . ' WHERE category_id = %d', $id )
		);
		foreach ( $parked as $space_id ) {
			Space::update( (int) $space_id, [ 'category_id' => 0 ] );
		}

		return parent::delete( $id );
	}

	/**
	 * Why `$parent_id` cannot be the parent of category `$id`, or null if it can.
	 *
	 * Categories nest two levels deep: a top-level category and its
	 * sub-categories. A parent must exist, must itself be top-level, and a
	 * category that already has sub-categories cannot become one. Those rules
	 * also rule out self-parenting and cycles.
	 *
	 * @param int $id        Category being written (0 when creating).
	 * @param int $parent_id Proposed parent (0 = top level).
	 * @return \WP_Error|null
	 */
	public static function parent_error( int $id, int $parent_id ): ?\WP_Error {
		if ( $parent_id <= 0 ) {
			return null;
		}

		$message = null;
		$parent  = static::find( $parent_id );
		if ( $parent_id === $id ) {
			$message = __( 'A category cannot be its own parent.', 'jetonomy' );
		} elseif ( ! $parent ) {
			$message = __( 'The parent category does not exist.', 'jetonomy' );
		} elseif ( (int) $parent->parent_id > 0 ) {
			$message = __( 'Categories nest two levels deep. Choose a top-level category as the parent.', 'jetonomy' );
		} elseif ( $id > 0 && static::count( [ 'parent_id' => $id ] ) > 0 ) {
			$message = __( 'This category has sub-categories, so it cannot become a sub-category itself.', 'jetonomy' );
		}

		return $message ? new \WP_Error( 'jetonomy_invalid_parent', $message, [ 'status' => 400 ] ) : null;
	}

	/**
	 * The top-level category `$id` sits under (itself when already top-level, 0 if missing).
	 *
	 * Visited-set walk so pre-2.0.1 rows with a cycle cannot loop.
	 *
	 * @param int $id Category id.
	 * @return int
	 */
	public static function top_level_ancestor( int $id ): int {
		$seen = [];
		while ( $id > 0 && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$row         = static::find( $id );
			if ( ! $row ) {
				return 0;
			}
			if ( (int) $row->parent_id <= 0 ) {
				return $id;
			}
			$id = (int) $row->parent_id;
		}
		return 0;
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
	 * Visible sub-categories grouped by parent id, in one query.
	 *
	 * Categories nest two levels deep, so this plus list_top_level() is the
	 * whole tree. Replaces one list_children() query per parent on the
	 * directory, the category page, the navigation block and the admin list.
	 *
	 * @param int|null $user_id    Viewer ID (null resolves to the current user).
	 * @param int[]    $parent_ids Limit to these parents (empty = every parent).
	 * @return array<int, object[]> Parent id => children ordered like list_children().
	 */
	public static function children_by_parent( ?int $user_id = null, array $parent_ids = [] ): array {
		[ $vis_where, $vis_values ] = self::listing_visibility_sql( $user_id );

		$parent_ids = array_values( array_filter( array_map( 'intval', $parent_ids ) ) );
		$in         = '';
		if ( ! empty( $parent_ids ) ) {
			$in         = ' AND parent_id IN (' . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ')';
			$vis_values = array_merge( $parent_ids, $vis_values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is %d placeholders, $vis_where literal SQL from listing_visibility_sql().
		$sql = 'SELECT * FROM ' . static::table() . " WHERE parent_id > 0{$in} AND {$vis_where} ORDER BY sort_order ASC, name ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = static::db()->get_results( empty( $vis_values ) ? $sql : static::db()->prepare( $sql, ...$vis_values ) ) ?: [];

		$grouped = [];
		foreach ( $rows as $row ) {
			$grouped[ (int) $row->parent_id ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Every visible category in display order (each parent followed by its
	 * sub-categories), with a `depth` of 0 or 1 for indenting a picker.
	 *
	 * @param int|null $user_id Viewer ID (null resolves to the current user).
	 * @return object[]
	 */
	public static function list_tree( ?int $user_id = null ): array {
		$children = self::children_by_parent( $user_id );
		$tree     = [];
		foreach ( self::list_top_level( $user_id ) as $top ) {
			$top->depth = 0;
			$tree[]     = $top;
			foreach ( $children[ (int) $top->id ] ?? [] as $child ) {
				$child->depth = 1;
				$tree[]       = $child;
			}
		}
		return $tree;
	}

	/**
	 * A category's name indented by its list_tree() depth, for a <select>.
	 *
	 * Three non-breaking spaces per level, as wp_dropdown_categories() does.
	 *
	 * @param object $category Row from list_tree().
	 * @return string Unescaped.
	 */
	public static function picker_label( object $category ): string {
		return str_repeat( "\u{00A0}", 3 * (int) ( $category->depth ?? 0 ) ) . $category->name;
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
		// number per parent, no need to paginate those) - one query for the page.
		$children = self::children_by_parent( null, array_column( $rows, 'id' ) );
		foreach ( $rows as $row ) {
			$row->children = $children[ (int) $row->id ] ?? [];
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
