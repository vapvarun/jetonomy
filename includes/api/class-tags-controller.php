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
				'permission_callback' => '__return_true',
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

		// Space tags.
		register_rest_route(
			$ns,
			'/space-tags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_space_tags' ],
				'permission_callback' => '__return_true',
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

	/**
	 * GET /space-tags — List space tags ordered by usage count or alphabetically.
	 */
	public function list_space_tags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = absint( $request->get_param( 'limit' ) ?? 30 );
		$sort  = $request->get_param( 'sort' ) ?? 'popular';

		global $wpdb;
		$space_tags_table = table( 'space_tags' );

		if ( 'alphabetical' === $sort ) {
			$order = 'name ASC';
		} else {
			$order = 'space_count DESC';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$space_tags_table} ORDER BY {$order} LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		) ?: [];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$space_tags_table}" );

		return $this->paginated_response(
			$tags,
			[
				'total'  => $total,
				'offset' => 0,
			]
		);
	}
}
