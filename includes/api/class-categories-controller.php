<?php
/**
 * Categories REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

class Categories_Controller extends Base_Controller {

	protected $rest_base = 'categories';

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/categories',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_categories' ),
					'args'                => $this->get_create_args(),
				),
			)
		);

		register_rest_route(
			$ns,
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_categories' ),
					'args'                => $this->get_update_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_categories' ),
				),
			)
		);
	}

	/**
	 * GET /categories — List all top-level categories with nested children.
	 *
	 * Categories nest two levels deep, so the tree is list_top_level() plus
	 * children_by_parent(): two visibility-filtered queries, both through the
	 * Category model, plus the shared tree-cached space grouping. Every node
	 * carries `spaces` and `children` (empty on a sub-category), the shape the
	 * companion app types declare.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$children      = Category::children_by_parent();
		$spaces_by_cat = Space::visible_by_category();

		$node = function ( object $category ) use ( $spaces_by_cat ): array {
			$item           = $this->prepare_category( $category );
			$item['spaces'] = $spaces_by_cat[ (int) $category->id ] ?? array();
			// The viewer's count, not the stored one: the stored total includes
			// private and hidden spaces the viewer cannot see.
			$item['space_count'] = count( $item['spaces'] );
			$item['children']    = array();
			return $item;
		};

		$items = array();
		foreach ( Category::list_top_level() as $category ) {
			$item             = $node( $category );
			$item['children'] = array_map( $node, $children[ (int) $category->id ] ?? array() );
			$items[]          = $item;
		}

		return $this->paginated_response( $items, array( 'total' => count( $items ) ) );
	}

	/**
	 * GET /categories/{id} — Get a single category with its spaces.
	 */
	public function get_item( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$category = Category::find_visible( $id );

		if ( ! $category ) {
			// 404, not 403: a category the viewer may not see must not be
			// distinguishable from one that does not exist. The mutation
			// handlers below keep the raw find() - they are already behind
			// jetonomy_manage_categories, which sees everything.
			return $this->not_found( 'Category' );
		}

		$data           = $this->prepare_category( $category );
		$data['spaces'] = Space::list_by_category( $id );
		// Matches the list above (see list_items()).
		$data['space_count'] = count( $data['spaces'] );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /categories — Create a new category.
	 */
	public function create_item( $request ) {
		$name = sanitize_text_field( $request->get_param( 'name' ) );

		if ( empty( $name ) ) {
			return $this->validation_error( sprintf( /* translators: %s: the singular Category label. */ __( '%s name is required.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category' ) ) );
		}

		// Category::create() turns this into a free slug (name when empty).
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );

		$data = array(
			'name'        => $name,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'parent_id'   => absint( $request->get_param( 'parent_id' ) ) ?: null,
			'icon'        => sanitize_text_field( (string) $request->get_param( 'icon' ) ),
			'color'       => (string) $request->get_param( 'color' ),
			'visibility'  => sanitize_text_field( (string) $request->get_param( 'visibility' ) ) ?: 'public',
			'sort_order'  => absint( $request->get_param( 'sort_order' ) ),
		);

		$parent_error = Category::parent_error( 0, (int) $data['parent_id'] );
		if ( $parent_error ) {
			return $parent_error;
		}

		$id = Category::create( array_filter( $data, fn( $v ) => null !== $v && '' !== $v ) );

		if ( ! $id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Failed to create %s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', false, true ) ),
				array( 'status' => 500 )
			);
		}

		$category = Category::find( $id );

		return new WP_REST_Response( $this->prepare_category( $category ), 201 );
	}

	/**
	 * PATCH /categories/{id} — Partially update a category.
	 */
	public function update_item( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$category = Category::find( $id );

		if ( ! $category ) {
			return $this->not_found( 'Category' );
		}

		$data = array();

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
			$data['parent_id'] = absint( $request->get_param( 'parent_id' ) );
		}
		if ( null !== $request->get_param( 'icon' ) ) {
			$data['icon'] = sanitize_text_field( $request->get_param( 'icon' ) );
		}
		if ( null !== $request->get_param( 'color' ) ) {
			$data['color'] = (string) $request->get_param( 'color' );
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

		$result = Category::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = Category::find( $id );

		return new WP_REST_Response( $this->prepare_category( $updated ), 200 );
	}

	/**
	 * DELETE /categories/{id} — Delete a category.
	 */
	public function delete_item( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$category = Category::find( $id );

		if ( ! $category ) {
			return $this->not_found( 'Category' );
		}

		$deleted = Category::delete( $id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		if ( ! $deleted ) {
			return new WP_Error(
				'jetonomy_delete_failed',
				sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Failed to delete %s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', false, true ) ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Format a category object for API output.
	 */
	private function prepare_category( object $category ): array {
		return array(
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
		);
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return array(
			'name'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'        => array(
				'type'     => 'string',
				'required' => false,
			),
			'description' => array(
				'type'     => 'string',
				'required' => false,
			),
			'parent_id'   => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
			),
			'icon'        => array(
				'type'     => 'string',
				'required' => false,
			),
			'color'       => array(
				'type'     => 'string',
				'required' => false,
			),
			'visibility'  => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'public', 'private', 'hidden' ),
			),
			'sort_order'  => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
			),
		);
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
