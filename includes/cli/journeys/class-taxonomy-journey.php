<?php
/**
 * Taxonomy journey — category + tag CRUD and lookup.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Category;
use Jetonomy\Models\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering the two Jetonomy taxonomies: categories (which
 * group spaces) and tags (which label posts). They share validation and
 * list-shape helpers so both domains live in a single class.
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method takes
 * plain primitives or assoc arrays, delegates to {@see Category} or
 * {@see Tag}, and returns a {@see Journey_Result}. Commands format the
 * result for the terminal; PHPUnit tests read the same fields and assert
 * on them.
 */
final class Taxonomy_Journey {

	/**
	 * Create a category.
	 *
	 * Required input keys: `name`, `slug`.
	 * Optional: `description`, `parent_id`.
	 *
	 * @param array<string,mixed> $input Create payload.
	 */
	public function create_category( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'name', 'slug' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$data = [
			'name' => (string) $input['name'],
			'slug' => (string) $input['slug'],
		];
		if ( isset( $input['description'] ) ) {
			$data['description'] = (string) $input['description'];
		}
		if ( isset( $input['parent_id'] ) ) {
			$data['parent_id'] = (int) $input['parent_id'];
		}

		$id = Category::create( $data );
		if ( ! $id ) {
			return Journey_Result::fail( 'Category::create() returned 0 — insert failed.' );
		}

		$row = Category::find( (int) $id );

		return Journey_Result::ok(
			[
				'id'        => (int) $id,
				'name'      => $data['name'],
				'slug'      => $row->slug ?? $data['slug'],
				'parent_id' => (int) ( $row->parent_id ?? 0 ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Update mutable fields on an existing category.
	 *
	 * Only a whitelist of safe columns is forwarded to Category::update() so
	 * callers cannot mutate counters or timestamps via typos.
	 *
	 * @param int                 $id      Category row ID.
	 * @param array<string,mixed> $changes Column → new value map.
	 */
	public function update_category( int $id, array $changes ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Category id must be positive.' );
		}

		$allowed = [ 'name', 'slug', 'description', 'parent_id', 'sort_order' ];
		$patch   = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $patch ) ) {
			return Journey_Result::fail( sprintf( 'No updatable fields provided. Allowed: %s', implode( ', ', $allowed ) ) );
		}

		$ok = Category::update( $id, $patch );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Category::update(%d) returned false.', $id ) );
		}

		return Journey_Result::ok(
			[
				'id'      => $id,
				'updated' => array_keys( $patch ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Delete a category by ID.
	 *
	 * Category inherits Model::delete() which returns bool|WP_Error.
	 *
	 * @param int $id Category row ID.
	 */
	public function delete_category( int $id ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Category id must be positive.' );
		}

		$result = Category::delete( $id );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( ! $result ) {
			return Journey_Result::fail( sprintf( 'Category::delete(%d) returned false.', $id ) );
		}

		return Journey_Result::ok( [ 'id' => $id ], [], $this->duration_ms( $start ) );
	}

	/**
	 * Fetch a category by ID.
	 *
	 * @param int $id Category row ID.
	 */
	public function get_category( int $id ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Category id must be positive.' );
		}

		$row = Category::find( $id );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Category %d not found.', $id ) );
		}

		return Journey_Result::ok( (array) $row, [], $this->duration_ms( $start ) );
	}

	/**
	 * Fetch a category by slug.
	 *
	 * @param string $slug Category slug.
	 */
	public function get_category_by_slug( string $slug ): Journey_Result {
		$start = microtime( true );

		if ( '' === $slug ) {
			return Journey_Result::fail( 'slug must not be empty.' );
		}

		$row = Category::find_by_slug( $slug );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Category with slug "%s" not found.', $slug ) );
		}

		return Journey_Result::ok( (array) $row, [], $this->duration_ms( $start ) );
	}

	/**
	 * List top-level categories (parent_id IS NULL or 0), shaped for render_list().
	 */
	public function list_top_level_categories(): Journey_Result {
		$start = microtime( true );

		$rows  = Category::list_top_level();
		$items = $this->shape_category_rows( $rows );

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'name', 'slug', 'parent_id', 'space_count', 'sort_order' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List the children of a given parent category, shaped for render_list().
	 *
	 * @param int $parent_id Parent category row ID.
	 */
	public function list_category_children( int $parent_id ): Journey_Result {
		$start = microtime( true );

		if ( $parent_id <= 0 ) {
			return Journey_Result::fail( 'parent_id must be positive.' );
		}

		$rows  = Category::list_children( $parent_id );
		$items = $this->shape_category_rows( $rows );

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'name', 'slug', 'parent_id', 'space_count', 'sort_order' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Find a tag by name, creating it if missing.
	 *
	 * To detect whether the tag was newly created vs looked up, the journey
	 * checks Tag::find_by_slug() before calling Tag::find_or_create(). The
	 * result carries a `created` boolean so callers can assert which path
	 * was taken.
	 *
	 * @param string $name Tag display name.
	 */
	public function create_or_get_tag( string $name ): Journey_Result {
		$start = microtime( true );

		if ( '' === trim( $name ) ) {
			return Journey_Result::fail( 'name must not be empty.' );
		}

		$slug     = sanitize_title( $name );
		$existing = Tag::find_by_slug( $slug );
		$created  = null === $existing;

		$id = Tag::find_or_create( $name );
		if ( ! $id ) {
			return Journey_Result::fail( 'Tag::find_or_create() returned 0 — insert failed.' );
		}

		$row = Tag::find( (int) $id );

		return Journey_Result::ok(
			[
				'id'      => (int) $id,
				'name'    => $row->name ?? $name,
				'slug'    => $row->slug ?? $slug,
				'created' => $created,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Delete a tag by ID.
	 *
	 * @param int $id Tag row ID.
	 */
	public function delete_tag( int $id ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Tag id must be positive.' );
		}

		$result = Tag::delete( $id );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( ! $result ) {
			return Journey_Result::fail( sprintf( 'Tag::delete(%d) returned false.', $id ) );
		}

		return Journey_Result::ok( [ 'id' => $id ], [], $this->duration_ms( $start ) );
	}

	/**
	 * Fetch a tag by slug.
	 *
	 * @param string $slug Tag slug.
	 */
	public function get_tag_by_slug( string $slug ): Journey_Result {
		$start = microtime( true );

		if ( '' === $slug ) {
			return Journey_Result::fail( 'slug must not be empty.' );
		}

		$row = Tag::find_by_slug( $slug );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Tag with slug "%s" not found.', $slug ) );
		}

		return Journey_Result::ok( (array) $row, [], $this->duration_ms( $start ) );
	}

	/**
	 * Attach a tag to a post.
	 *
	 * @param int $post_id Post row ID.
	 * @param int $tag_id  Tag row ID.
	 */
	public function attach_tag_to_post( int $post_id, int $tag_id ): Journey_Result {
		$start = microtime( true );

		if ( $post_id <= 0 || $tag_id <= 0 ) {
			return Journey_Result::fail( 'post_id and tag_id must both be positive.' );
		}

		Tag::attach_to_post( $post_id, $tag_id );

		return Journey_Result::ok(
			[
				'post_id' => $post_id,
				'tag_id'  => $tag_id,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Detach a tag from a post.
	 *
	 * @param int $post_id Post row ID.
	 * @param int $tag_id  Tag row ID.
	 */
	public function detach_tag_from_post( int $post_id, int $tag_id ): Journey_Result {
		$start = microtime( true );

		if ( $post_id <= 0 || $tag_id <= 0 ) {
			return Journey_Result::fail( 'post_id and tag_id must both be positive.' );
		}

		Tag::detach_from_post( $post_id, $tag_id );

		return Journey_Result::ok(
			[
				'post_id' => $post_id,
				'tag_id'  => $tag_id,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List every tag attached to a post, shaped for render_list().
	 *
	 * @param int $post_id Post row ID.
	 */
	public function list_tags_for_post( int $post_id ): Journey_Result {
		$start = microtime( true );

		if ( $post_id <= 0 ) {
			return Journey_Result::fail( 'post_id must be positive.' );
		}

		$rows  = Tag::list_for_post( $post_id );
		$items = $this->shape_tag_rows( $rows );

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'name', 'slug', 'post_count' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List the most popular tags by post_count.
	 *
	 * @param int $limit Maximum number of tags to return.
	 */
	public function list_popular_tags( int $limit = 20 ): Journey_Result {
		$start = microtime( true );

		if ( $limit <= 0 ) {
			return Journey_Result::fail( 'limit must be positive.' );
		}

		$rows  = Tag::list_popular( $limit );
		$items = $this->shape_tag_rows( $rows );

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'name', 'slug', 'post_count' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Search tags by name (partial match).
	 *
	 * @param string $query Non-empty search string.
	 * @param int    $limit Maximum number of results.
	 */
	public function search_tags( string $query, int $limit = 10 ): Journey_Result {
		$start = microtime( true );

		if ( '' === trim( $query ) ) {
			return Journey_Result::fail( 'query must not be empty.' );
		}
		if ( $limit <= 0 ) {
			return Journey_Result::fail( 'limit must be positive.' );
		}

		$rows  = Tag::search( $query, $limit );
		$items = $this->shape_tag_rows( $rows );

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'name', 'slug', 'post_count' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Shape Category rows into an items array suitable for render_list().
	 *
	 * @param array<int,object> $rows Raw DB rows from the Category model.
	 * @return array<int,array<string,mixed>>
	 */
	private function shape_category_rows( array $rows ): array {
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'          => (int) $row->id,
				'name'        => (string) ( $row->name ?? '' ),
				'slug'        => (string) ( $row->slug ?? '' ),
				'parent_id'   => (int) ( $row->parent_id ?? 0 ),
				'space_count' => (int) ( $row->space_count ?? 0 ),
				'sort_order'  => (int) ( $row->sort_order ?? 0 ),
			];
		}
		return $items;
	}

	/**
	 * Shape Tag rows into an items array suitable for render_list().
	 *
	 * @param array<int,object> $rows Raw DB rows from the Tag model.
	 * @return array<int,array<string,mixed>>
	 */
	private function shape_tag_rows( array $rows ): array {
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'         => (int) $row->id,
				'name'       => (string) ( $row->name ?? '' ),
				'slug'       => (string) ( $row->slug ?? '' ),
				'post_count' => (int) ( $row->post_count ?? 0 ),
			];
		}
		return $items;
	}

	/**
	 * Return any required keys that are missing or empty in the input array.
	 *
	 * @param array<string,mixed> $input Input payload.
	 * @param array<int,string>   $keys  Required key names.
	 * @return array<int,string> Missing key names; empty if all present.
	 */
	private function require_keys( array $input, array $keys ): array {
		$missing = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
