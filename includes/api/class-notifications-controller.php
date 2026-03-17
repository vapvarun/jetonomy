<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Notification;

class Notifications_Controller extends Base_Controller {

	protected string $rest_base = 'notifications';

	/**
	 * Register all REST routes for notifications.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		// Collection.
		register_rest_route( $ns, '/notifications', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_items' ],
			'permission_callback' => '__return_true',
			'args'                => $this->get_collection_params(),
		] );

		// Unread count — registered before the (?P<id>\d+) route so it wins.
		register_rest_route( $ns, '/notifications/unread-count', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'unread_count' ],
			'permission_callback' => '__return_true',
		] );

		// Mark all read.
		register_rest_route( $ns, '/notifications/mark-all-read', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'mark_all_read' ],
			'permission_callback' => '__return_true',
		] );

		// Single notification.
		register_rest_route( $ns, '/notifications/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'mark_read' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * GET /notifications — List notifications for the current user.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$pagination    = $this->get_pagination( $request );
		$limit         = (int) $pagination['limit'];
		$offset        = (int) $pagination['offset'];
		$notifications = Notification::list_for_user( $user_id, $limit, $offset );

		$items = array_map( [ $this, 'prepare_notification' ], $notifications );

		return $this->paginated_response( $items, [
			'has_more' => count( $items ) === $limit,
		] );
	}

	/**
	 * PATCH /notifications/{id} — Mark a single notification as read.
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id           = absint( $request->get_param( 'id' ) );
		$notification = Notification::find( $id );

		if ( ! $notification ) {
			return $this->not_found( 'Notification' );
		}

		// Verify the notification belongs to the current user.
		if ( (int) $notification->user_id !== $user_id ) {
			return $this->permission_error();
		}

		Notification::mark_read( $id );

		$updated = Notification::find( $id );

		return new WP_REST_Response( $this->prepare_notification( $updated ), 200 );
	}

	/**
	 * POST /notifications/mark-all-read — Mark all notifications read for the current user.
	 */
	public function mark_all_read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		Notification::mark_all_read( $user_id );

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * GET /notifications/unread-count — Return the unread notification count.
	 *
	 * Sets Cache-Control: max-age=15 to allow short-lived client-side caching.
	 */
	public function unread_count( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$count    = Notification::unread_count( $user_id );
		$response = new WP_REST_Response( [ 'count' => $count ], 200 );
		$response->header( 'Cache-Control', 'max-age=15' );

		return $response;
	}

	/**
	 * Format a notification row for API output.
	 */
	private function prepare_notification( ?object $notification ): array {
		if ( ! $notification ) {
			return [];
		}

		return [
			'id'          => (int) $notification->id,
			'user_id'     => (int) $notification->user_id,
			'type'        => $notification->type ?? '',
			'object_type' => $notification->object_type ?? null,
			'object_id'   => $notification->object_id ? (int) $notification->object_id : null,
			'actor_id'    => $notification->actor_id ? (int) $notification->actor_id : null,
			'is_read'     => (bool) ( $notification->is_read ?? false ),
			'created_at'  => $notification->created_at ?? null,
		];
	}
}
