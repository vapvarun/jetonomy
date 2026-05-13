<?php
/**
 * Subscriptions REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Subscription;
use function Jetonomy\table;

class Subscriptions_Controller extends Base_Controller {

	protected $rest_base = 'subscriptions';

	/**
	 * Register all REST routes for subscriptions.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Collection.
		register_rest_route(
			$ns,
			'/subscriptions',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_create_args(),
				],
			]
		);

		// Single item.
		register_rest_route(
			$ns,
			'/subscriptions/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			]
		);
	}

	/**
	 * GET /subscriptions — List subscriptions for the current user.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		global $wpdb;
		$tbl = table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		) ?: [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d",
				$user_id
			)
		);

		$items = array_map( [ $this, 'prepare_subscription' ], $rows );

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
		);
	}

	/**
	 * POST /subscriptions — Subscribe to a space or post.
	 */
	public function create_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );

		if ( ! in_array( $object_type, [ 'space', 'post' ], true ) ) {
			return $this->validation_error( __( 'object_type must be "space" or "post".', 'jetonomy' ) );
		}

		if ( ! $object_id ) {
			return $this->validation_error( __( 'A valid object_id is required.', 'jetonomy' ) );
		}

		$via = sanitize_key( (string) ( $request->get_param( 'via' ) ?? 'both' ) );
		if ( ! in_array( $via, [ 'web', 'email', 'both' ], true ) ) {
			$via = 'both';
		}

		$id = Subscription::subscribe( $user_id, $object_type, $object_id, $via );

		// When INSERT IGNORE fires on a duplicate, insert_id is 0.
		// Fetch the row regardless to return a consistent response.
		global $wpdb;
		$tbl = table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE user_id = %d AND object_type = %s AND object_id = %d",
				$user_id,
				$object_type,
				$object_id
			)
		);

		$status = $id > 0 ? 201 : 200;

		return new WP_REST_Response( $this->prepare_subscription( $row ), $status );
	}

	/**
	 * DELETE /subscriptions/{id} — Remove a subscription.
	 */
	public function delete_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id = absint( $request->get_param( 'id' ) );

		global $wpdb;
		$tbl = table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE id = %d",
				$id
			)
		);

		if ( ! $subscription ) {
			return $this->not_found( 'Subscription' );
		}

		// Verify ownership.
		if ( (int) $subscription->user_id !== $user_id ) {
			return $this->permission_error();
		}

		Subscription::unsubscribe(
			$user_id,
			$subscription->object_type,
			(int) $subscription->object_id
		);

		return new WP_REST_Response(
			[
				'deleted' => true,
				'id'      => $id,
			],
			200
		);
	}

	/**
	 * Format a subscription row for API output.
	 */
	private function prepare_subscription( ?object $subscription ): array {
		if ( ! $subscription ) {
			return [];
		}

		return [
			'id'          => (int) $subscription->id,
			'user_id'     => (int) $subscription->user_id,
			'object_type' => $subscription->object_type ?? '',
			'object_id'   => (int) $subscription->object_id,
			'via'         => $subscription->notify_via ?? 'both',
			'created_at'  => $subscription->created_at ?? null,
		];
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return [
			'object_type' => [
				'type'     => 'string',
				'required' => true,
				'enum'     => [ 'space', 'post' ],
			],
			'object_id'   => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
			'via'         => [
				'type'     => 'string',
				'required' => false,
				'enum'     => [ 'web', 'email', 'both' ],
				'default'  => 'both',
			],
		];
	}
}
