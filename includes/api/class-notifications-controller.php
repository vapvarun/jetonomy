<?php
/**
 * Notifications REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

class Notifications_Controller extends Base_Controller {

	protected $rest_base = 'notifications';

	/**
	 * Register all REST routes for notifications.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Collection.
		register_rest_route(
			$ns,
			'/notifications',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_collection_params(),
			]
		);

		// Unread count — registered before the (?P<id>\d+) route so it wins.
		register_rest_route(
			$ns,
			'/notifications/unread-count',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'unread_count' ],
				'permission_callback' => '__return_true',
			]
		);

		// Mark all read.
		register_rest_route(
			$ns,
			'/notifications/mark-all-read',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_all_read' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			]
		);

		// Single notification — ownership check stays inside mark_read() because
		// it depends on the notification row's user_id field.
		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'mark_read' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			]
		);
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
		$total         = Notification::count( [ 'user_id' => $user_id ] );

		$items = array_map( [ $this, 'prepare_notification' ], $notifications );

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
		);
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

		$actor_id = (int) ( $notification->actor_id ?? 0 );
		$actor    = $actor_id ? get_userdata( $actor_id ) : null;

		$object_type = $notification->object_type ?? '';
		$object_id   = $notification->object_id ? (int) $notification->object_id : 0;

		$data = [
			'id'           => (int) $notification->id,
			'user_id'      => (int) $notification->user_id,
			'type'         => $notification->type ?? '',
			'object_type'  => $object_type ?: null,
			'object_id'    => $object_id ?: null,
			'actor_id'     => $actor_id ?: null,
			'is_read'      => (bool) ( $notification->is_read ?? false ),
			'created_at'   => $notification->created_at ?? null,
			// Enriched actor data (for app clients + JS rendering)
			'message'      => $notification->message ?? '',
			'actor_name'   => $actor ? $actor->display_name : __( 'System', 'jetonomy' ),
			'actor_avatar' => $actor ? get_avatar_url( $actor_id, [ 'size' => 64 ] ) : '',
			'actor_login'  => $actor ? $actor->user_login : '',
			'time_ago'     => $notification->created_at ? human_time_diff( strtotime( $notification->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '',
			'profile_url'  => $actor_id ? \Jetonomy\get_profile_url( $actor_id ) : '',
			'object_url'   => $this->resolve_notification_url( $notification, $object_type, $object_id ),
		];

		/**
		 * Filter the REST response data for a single notification.
		 *
		 * @param array  $data         Prepared response data.
		 * @param object $notification Raw notification row object.
		 * @param null   $request      WP_REST_Request (null in non-request contexts).
		 */
		$data = apply_filters( 'jetonomy_rest_prepare_notification', $data, $notification, null );

		return $data;
	}

	/**
	 * Resolve URL for a notification, handling special types like badge.
	 */
	private function resolve_notification_url( object $notification, string $object_type, int $object_id ): string {
		if ( 'badge' === $object_type ) {
			$user = get_userdata( (int) $notification->user_id );
			if ( $user ) {
				return \Jetonomy\base_url() . '/u/' . rawurlencode( $user->user_login ) . '/';
			}
			return '';
		}

		if ( $object_type && $object_id ) {
			return $this->resolve_object_url( $object_type, $object_id );
		}

		return '';
	}

	/**
	 * Resolve a deep-link URL for a notification's target object.
	 *
	 * @param string $object_type 'post', 'reply', or 'user'.
	 * @param int    $object_id   The object ID.
	 * @return string URL or empty string if unresolvable.
	 */
	private function resolve_object_url( string $object_type, int $object_id ): string {
		$settings  = get_option( 'jetonomy_settings', [] );
		$base_slug = $settings['base_slug'] ?? 'community';

		if ( 'post' === $object_type ) {
			$post = Post::find( $object_id );
			if ( ! $post ) {
				return '';
			}
			$space = Space::find( (int) $post->space_id );
			if ( ! $space ) {
				return '';
			}
			return home_url( '/' . $base_slug . '/s/' . $space->slug . '/t/' . $post->slug . '/' );
		}

		if ( 'reply' === $object_type ) {
			$reply = Reply::find( $object_id );
			if ( ! $reply ) {
				return '';
			}
			$post = Post::find( (int) $reply->post_id );
			if ( ! $post ) {
				return '';
			}
			$space = Space::find( (int) $post->space_id );
			if ( ! $space ) {
				return '';
			}
			return home_url( '/' . $base_slug . '/s/' . $space->slug . '/t/' . $post->slug . '/#reply-' . $object_id );
		}

		if ( 'user' === $object_type ) {
			return \Jetonomy\get_profile_url( $object_id );
		}

		return '';
	}
}
