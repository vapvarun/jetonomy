<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

class Categories_Controller extends Base_Controller {

	protected string $rest_base = 'categories';

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		register_rest_route( $ns, '/categories', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'manage_permission_check' ],
				'args'                => $this->get_create_args(),
			],
		] );

		register_rest_route( $ns, '/categories/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'manage_permission_check' ],
				'args'                => $this->get_update_args(),
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'manage_permission_check' ],
			],
		] );
	}

	/**
	 * Permission check for write operations.
	 */
	public function manage_permission_check(): bool|WP_Error {
		if ( ! current_user_can( 'jetonomy_manage_categories' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * GET /categories — List all top-level categories with nested children.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$top_level = Category::list_top_level();
		$items     = [];

		foreach ( $top_level as $category ) {
			$item             = $this->prepare_category( $category );
			$item['children'] = $this->get_nested_children( (int) $category->id );
			$items[]          = $item;
		}

		return $this->paginated_response( $items, [ 'total' => count( $items ) ] );
	}

	/**
	 * Recursively fetch and format child categories.
	 */
	private function get_nested_children( int $parent_id ): array {
		$children = Category::list_children( $parent_id );
		$result   = [];

		foreach ( $children as $child ) {
			$item             = $this->prepare_category( $child );
			$item['children'] = $this->get_nested_children( (int) $child->id );
			$result[]         = $item;
		}

		return $result;
	}

	/**
	 * GET /categories/{id} — Get a single category with its spaces.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = absint( $request->get_param( 'id' ) );
		$category = Category::find( $id );

		if ( ! $category ) {
			return $this->not_found( 'Category' );
		}

		$data           = $this->prepare_category( $category );
		$data['spaces'] = Space::list_by_category( $id );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /categories — Create a new category.
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$name = sanitize_text_field( $request->get_param( 'name' ) );

		if ( empty( $name ) ) {
			return $this->validation_error( __( 'Category name is required.', 'jetonomy' ) );
		}

		$slug = $request->get_param( 'slug' )
			? sanitize_title( $request->get_param( 'slug' ) )
			: sanitize_title( $name );

		// Ensure slug is unique.
		$slug = $this->unique_slug( $slug );

		$data = [
			'name'        => $name,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'parent_id'   => absint( $request->get_param( 'parent_id' ) ) ?: null,
			'icon'        => sanitize_text_field( (string) $request->get_param( 'icon' ) ),
			'color'       => sanitize_hex_color( (string) $request->get_param( 'color' ) ) ?: sanitize_text_field( (string) $request->get_param( 'color' ) ),
			'visibility'  => sanitize_text_field( (string) $request->get_param( 'visibility' ) ) ?: 'public',
			'sort_order'  => absint( $request->get_param( 'sort_order' ) ),
		];

		$id = Category::create( array_filter( $data, fn( $v ) => null !== $v && '' !== $v ) );

		if ( ! $id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create category.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		$category = Category::find( $id );

		return new WP_REST_Response( $this->prepare_category( $category ), 201 );
	}

	/**
	 * PATCH /categories/{id} — Partially update a category.
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = absint( $request->get_param( 'id' ) );
		$category = Category::find( $id );

		if ( ! $category ) {
			return $this->not_found( 'Category' );
		}

		$data = [];

		if ( null !== $request->get_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( $request->get_param( 'name' ) );
		}
		if ( null !== $request->get_param( 'slug' ) ) {
			$data['slug'] = sanitize_title( $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}
		if ( null !== $request->get_param( 'parent_id' ) ) {
			$data['parent_id'] = absint( $request->get_param( 'parent_id' ) ) ?: null;
		}
		if ( null !== $request->get_param( 'icon' ) ) {
			$data['icon'] = sanitize_text_field( $request->get_param( 'icon' ) );
		}
		if ( null !== $request->get_param( 'color' ) ) {
			$data['color'] = sanitize_hex_color( $request->get_param( 'color' ) ) ?: sanitize_text_field( $request->get_param( 'color' ) );
		}
		if ( null !== $request->get_param( 'visibility' ) ) {
			$data['visibility'] = sanitize_text_field( $request->get_param( 'visibility' ) );
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$data['sort_order'] = absint( $request->get_param( 'sort_order' ) );
		}

		if ( empty( $data ) ) {
			return $this->validation_error( __( 'No fields provided for update.', 'jetonomy' ) );
		}

		Category::update( $id, $data );

		$updated = Category::find( $id );

		return new WP_REST_Response( $this->prepare_category( $updated ), 200 );
	}

	/**
	 * DELETE /categories/{id} — Delete a category.
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = absint( $request->get_param( 'id' ) );
		$category = Category::find( $id );

		if ( ! $category ) {
			return $this->not_found( 'Category' );
		}

		$deleted = Category::delete( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'jetonomy_delete_failed',
				__( 'Failed to delete category.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * Format a category object for API output.
	 */
	private function prepare_category( object $category ): array {
		return [
			'id'          => (int) $category->id,
			'name'        => $category->name,
			'slug'        => $category->slug,
			'description' => $category->description ?? '',
			'parent_id'   => $category->parent_id ? (int) $category->parent_id : null,
			'icon'        => $category->icon ?? '',
			'color'       => $category->color ?? '',
			'visibility'  => $category->visibility ?? 'public',
			'sort_order'  => (int) ( $category->sort_order ?? 0 ),
			'space_count' => (int) ( $category->space_count ?? 0 ),
			'created_at'  => $category->created_at ?? null,
		];
	}

	/**
	 * Generate a unique slug by appending a numeric suffix if needed.
	 */
	private function unique_slug( string $base_slug ): string {
		$slug    = $base_slug;
		$counter = 1;

		while ( Category::find_by_slug( $slug ) ) {
			$slug = $base_slug . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return [
			'name'        => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'slug'        => [ 'type' => 'string', 'required' => false ],
			'description' => [ 'type' => 'string', 'required' => false ],
			'parent_id'   => [ 'type' => 'integer', 'required' => false, 'minimum' => 0 ],
			'icon'        => [ 'type' => 'string', 'required' => false ],
			'color'       => [ 'type' => 'string', 'required' => false ],
			'visibility'  => [ 'type' => 'string', 'required' => false, 'enum' => [ 'public', 'private', 'hidden' ] ],
			'sort_order'  => [ 'type' => 'integer', 'required' => false, 'minimum' => 0 ],
		];
	}

	/**
	 * Args for update_item (all optional).
	 */
	private function get_update_args(): array {
		$args = $this->get_create_args();
		foreach ( $args as &$arg ) {
			$arg['required'] = false;
		}
		return $args;
	}
}
