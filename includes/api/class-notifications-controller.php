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

		// Bulk action — mark read / delete a set of notifications in one round-trip.
		register_rest_route(
			$ns,
			'/notifications/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_action' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				'args'                => [
					'action' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'mark_read', 'delete' ],
					],
					'ids'    => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		// Single notification PATCH (mark read) + DELETE (dismiss).
		// Ownership checks live inside the callbacks since they depend on the
		// row's user_id field, not a static cap.
		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'mark_read' ],
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_notification' ],
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				],
			]
		);
	}

	/**
	 * GET /notifications — List notifications for the current user.
	 *
	 * Accepts `?filter=all|unread|mentions|replies|votes|badges`. Uses the
	 * target-enriched list method so every row already carries the post /
	 * space / reply slugs it needs — no per-row lookups required for url
	 * resolution. Filter slugs that don't match a known bucket collapse to
	 * `all` so the endpoint never 400s on a malformed query.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];
		$filter     = self::sanitize_filter( (string) $request->get_param( 'filter' ) );

		$notifications = Notification::list_for_user_with_targets( $user_id, $limit, $offset, $filter );
		$total         = Notification::count_for_user( $user_id, $filter );

		$items = array_map( [ $this, 'prepare_notification' ], $notifications );

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
				'filter' => $filter,
			]
		);
	}

	/**
	 * DELETE /notifications/{id} — Dismiss a single notification.
	 *
	 * Ownership is enforced via Notification::delete_for_user(): the row must
	 * match BOTH the id and the current user. A forged id from another user
	 * silently affects zero rows — the response still 200s with deleted=0 so
	 * the client can detect the no-op and refresh.
	 */
	public function delete_notification( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id      = absint( $request->get_param( 'id' ) );
		$deleted = Notification::delete_for_user( $user_id, [ $id ] );

		return new WP_REST_Response(
			[
				'success' => true,
				'deleted' => $deleted,
			],
			200
		);
	}

	/**
	 * POST /notifications/bulk — Run an action over a set of notifications.
	 *
	 * Body: `{ action: 'mark_read'|'delete', ids: [int, ...] }`.
	 *
	 * Both actions enforce ownership inside the model (WHERE user_id = %d) so
	 * a forged id list can never affect another user's rows. The response
	 * returns the number of rows actually changed so the client can update
	 * counters without a follow-up GET.
	 */
	public function bulk_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$action = (string) $request->get_param( 'action' );
		$ids    = (array) $request->get_param( 'ids' );

		if ( empty( $ids ) ) {
			return new WP_Error( 'jetonomy_invalid_ids', __( 'No notifications selected.', 'jetonomy' ), [ 'status' => 400 ] );
		}

		if ( 'delete' === $action ) {
			$affected = Notification::delete_for_user( $user_id, $ids );
		} else {
			$affected = Notification::mark_read_for_user( $user_id, $ids );
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'action'   => $action,
				'affected' => $affected,
			],
			200
		);
	}

	/**
	 * Sanitize an incoming filter slug against the canonical set.
	 *
	 * Kept in sync with Notification::filter_where() so the controller and the
	 * model agree on the same vocabulary.
	 *
	 * @param string $filter Raw input from the query string.
	 * @return string A known filter slug, or `all` for unknown input.
	 */
	private static function sanitize_filter( string $filter ): string {
		$allowed = [ 'all', 'unread', 'mentions', 'replies', 'votes', 'badges' ];
		return in_array( $filter, $allowed, true ) ? $filter : 'all';
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
	 *
	 * Prefers pre-joined slug columns (`post_slug`, `space_slug`, `reply_id`,
	 * `reply_post_slug`, `reply_space_slug`) when the row was loaded through
	 * Notification::list_for_user_with_targets(), and falls back to per-row
	 * model lookups for callers that loaded the row another way (e.g.
	 * mark_read which uses Notification::find).
	 */
	private function resolve_notification_url( object $notification, string $object_type, int $object_id ): string {
		if ( 'badge' === $object_type ) {
			$user = get_userdata( (int) $notification->user_id );
			if ( $user ) {
				// #jt-badges anchor = the profile badges section (Pro custom-badges).
				// Mirrors templates/views/notifications.php so the web page and the
				// REST/app dropdown resolve badge notifications identically.
				return \Jetonomy\base_url() . '/u/' . rawurlencode( $user->user_login ) . '/#jt-badges';
			}
			return '';
		}

		// Fast path: pre-joined slugs come from list_for_user_with_targets().
		$base = \Jetonomy\base_url();

		if ( 'post' === $object_type && ! empty( $notification->post_slug ) && ! empty( $notification->space_slug ) ) {
			return $base . '/s/' . $notification->space_slug . '/t/' . $notification->post_slug . '/';
		}

		if ( 'reply' === $object_type && ! empty( $notification->reply_post_slug ) && ! empty( $notification->reply_space_slug ) ) {
			return $base . '/s/' . $notification->reply_space_slug . '/t/' . $notification->reply_post_slug . '/#reply-' . $object_id;
		}

		// Slow path: per-row model lookup for callers that didn't use the JOIN.
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
		return \Jetonomy\notification_deep_link( $object_type, $object_id );
	}
}
