<?php
/**
 * Tags REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Tag;
use Jetonomy\API\REST_Auth;
use function Jetonomy\table;

class Tags_Controller extends Base_Controller {

	protected $rest_base = 'tags';

	/**
	 * Register REST routes for tags.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Post tags.
		register_rest_route(
			$ns,
			'/tags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_tags' ],
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
				'args'                => [
					'limit' => [
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 100,
					],
					'sort'  => [
						'type'    => 'string',
						'default' => 'popular',
						'enum'    => [ 'popular', 'alphabetical' ],
					],
				],
			]
		);

		/*
		 * Tag mutations. Until 1.9.4 tags were the one taxonomy with NO REST
		 * writes at all - create, update, delete and bulk-delete existed only
		 * as wp-admin AJAX, which breaks the project's REST-first rule and made
		 * tag management unreachable from the app or any integration.
		 *
		 * Validation deliberately mirrors Admin\Ajax\Tags_Handler so the two
		 * paths cannot drift: name required, slug derived from name when empty,
		 * slug unique. Capability matches too (jetonomy_manage_settings).
		 */
		register_rest_route(
			$ns,
			'/tags',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_tag' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_settings' ),
				'args'                => [
					'name' => [
						'type'     => 'string',
						'required' => true,
					],
					'slug' => [
						'type' => 'string',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/tags/(?P<id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_tag' ],
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_settings' ),
					'args'                => [
						'name' => [ 'type' => 'string' ],
						'slug' => [ 'type' => 'string' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_tag' ],
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_settings' ),
				],
			]
		);

		// /space-tags was removed in 1.5.0: the jt_space_tags tables never
		// gained a writer, so the endpoint could only ever return an empty
		// list (audit A5).
	}

	/**
	 * POST /tags — create a tag.
	 *
	 * Named create_tag(), not create_item(): WP_REST_Controller declares the
	 * reserved names without a return type, so re-declaring one here is a fatal
	 * signature mismatch. list_tags() above sets the same convention.
	 */
	public function create_tag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$slug = sanitize_title( (string) ( $request->get_param( 'slug' ) ?: $name ) );

		if ( '' === $name ) {
			return new WP_Error( 'jetonomy_tag_name_required', __( 'Name is required.', 'jetonomy' ), [ 'status' => 400 ] );
		}
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( Tag::exists( $slug ) ) {
			return new WP_Error( 'jetonomy_tag_slug_taken', __( 'A tag with that slug already exists.', 'jetonomy' ), [ 'status' => 409 ] );
		}

		$id = Tag::insert(
			[
				'name' => $name,
				'slug' => $slug,
			]
		);

		if ( ! $id ) {
			return new WP_Error( 'jetonomy_tag_create_failed', __( 'Failed to create tag.', 'jetonomy' ), [ 'status' => 500 ] );
		}

		return new WP_REST_Response( [ 'data' => Tag::find( (int) $id ) ], 201 );
	}

	/**
	 * PATCH /tags/{id} — rename a tag or change its slug.
	 */
	public function update_tag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( ! Tag::find( $id ) ) {
			return new WP_Error( 'jetonomy_tag_not_found', __( 'Tag not found.', 'jetonomy' ), [ 'status' => 404 ] );
		}

		$data = [];

		if ( null !== $request->get_param( 'name' ) ) {
			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error( 'jetonomy_tag_name_empty', __( 'Name cannot be empty.', 'jetonomy' ), [ 'status' => 400 ] );
			}
			$data['name'] = $name;
		}

		if ( null !== $request->get_param( 'slug' ) ) {
			$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
			if ( '' === $slug ) {
				return new WP_Error( 'jetonomy_tag_slug_empty', __( 'Slug cannot be empty.', 'jetonomy' ), [ 'status' => 400 ] );
			}
			$existing = Tag::find_by_slug( $slug );
			if ( $existing && (int) $existing->id !== $id ) {
				return new WP_Error( 'jetonomy_tag_slug_taken', __( 'Another tag already uses that slug.', 'jetonomy' ), [ 'status' => 409 ] );
			}
			$data['slug'] = $slug;
		}

		if ( ! $data ) {
			return new WP_Error( 'jetonomy_tag_no_changes', __( 'No data to update.', 'jetonomy' ), [ 'status' => 400 ] );
		}

		if ( ! Tag::update( $id, $data ) ) {
			return new WP_Error( 'jetonomy_tag_update_failed', __( 'Failed to update tag.', 'jetonomy' ), [ 'status' => 500 ] );
		}

		return new WP_REST_Response( [ 'data' => Tag::find( $id ) ], 200 );
	}

	/**
	 * DELETE /tags/{id} — delete a tag and detach it from every post.
	 */
	public function delete_tag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( ! Tag::find( $id ) ) {
			return new WP_Error( 'jetonomy_tag_not_found', __( 'Tag not found.', 'jetonomy' ), [ 'status' => 404 ] );
		}

		if ( ! Tag::delete_with_relations( $id ) ) {
			return new WP_Error( 'jetonomy_tag_delete_failed', __( 'Failed to delete tag.', 'jetonomy' ), [ 'status' => 500 ] );
		}

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => $id,
			],
			200
		);
	}

	/**
	 * GET /tags — List post tags ordered by popularity or alphabetically.
	 */
	public function list_tags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = absint( $request->get_param( 'limit' ) ?? 30 );
		$sort  = $request->get_param( 'sort' ) ?? 'popular';

		global $wpdb;
		$tags_table = table( 'tags' );

		if ( 'alphabetical' === $sort ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$tags = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tags_table} ORDER BY name ASC LIMIT %d",
					$limit
				)
			) ?: [];
		} else {
			$tags = Tag::list_popular( $limit );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tags_table}" );

		return $this->paginated_response(
			$tags,
			[
				'total'  => $total,
				'offset' => 0,
			]
		);
	}
}
