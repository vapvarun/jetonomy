<?php
/**
 * Per-space moderation REST API.
 *
 * Exposes /jetonomy/v1/spaces/{id}/moderation/* so space moderators and
 * space admins can list and act on flags scoped to a single space
 * without needing the global jetonomy_moderate capability.
 *
 * All permission decisions delegate to Moderation_Permissions + the
 * Moderation_Service kernel; this controller is intentionally thin.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Space;
use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Moderation\Moderation_Service;

class Space_Moderation_Controller extends Base_Controller {

	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/moderation/flags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_flags' ],
				'permission_callback' => [ $this, 'require_view_space_queue' ],
				'args'                => [
					'id'     => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
					// Same contract as the global queue (/moderation/flags). Without
					// this the Upheld/Dismissed chips on the per-space screen sent a
					// status the route never registered, WP dropped it silently, and
					// the filter returned pending rows forever — dead UI that looked
					// like "no results" rather than "this filter does nothing".
					'status' => [
						'type'    => 'string',
						'default' => 'pending',
						'enum'    => [ 'pending', 'valid', 'dismissed', 'all' ],
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/moderation/flags/(?P<flag_id>\d+)/resolve',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'resolve_flag' ],
				// REST_Auth handles login + nonce; the per-space queue gate
				// (which needs the path-bound `id`) runs in the handler via
				// Moderation_Permissions::can_view_space_queue.
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				'args'                => [
					'status' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'valid', 'dismissed' ],
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/moderation/(?P<action>approve|spam|trash)/(?P<type>post|reply)/(?P<obj_id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'act_on_object' ],
				// Same shape as resolve_flag — gate is per-space + per-object
				// inside the handler.
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			]
		);
	}

	/**
	 * Permission callback: caller must be allowed to view the target space's queue.
	 *
	 * Per-action permission (can_act_on_flag / can_act_on_object) runs again
	 * inside Moderation_Service so per-object checks remain authoritative,
	 * but the route-level gate keeps strangers from even seeing 404s.
	 */
	public function require_view_space_queue( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$space_id = absint( $request->get_param( 'id' ) );
		if ( ! Space::find( $space_id ) ) {
			return $this->not_found( 'Space' );
		}
		if ( ! Moderation_Permissions::can_view_space_queue( $user_id, $space_id ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * GET /spaces/{id}/moderation/flags — pending flags scoped to this space.
	 */
	public function list_flags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = absint( $request->get_param( 'id' ) );

		// Brought to parity with the global queue (Moderation_Controller::list_flags).
		// This route shows the SAME rows to the SAME moderator and had none of it:
		// it listed pending-only regardless of the requested status, returned every
		// row in the space (limit was ignored — 34 rows against limit=20), and
		// carried no reporter, no total and no has_more. All three were fixed on the
		// global route and never migrated here (Basecamp 10092724637, 10092652706).
		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];
		$status     = (string) ( $request->get_param( 'status' ) ?: 'pending' );

		// The permission gate stays in the service — it is what makes this route
		// space-scoped rather than a way to read another space's queue.
		if ( ! Moderation_Permissions::can_view_space_queue( get_current_user_id(), $space_id ) ) {
			return new WP_REST_Response(
				[
					'data' => [],
					'meta' => [ 'total' => 0, 'space_id' => $space_id ],
				],
				200
			);
		}

		$flags = Flag::list_by_status_in_space( $status, $space_id, $limit, $offset );
		$flags = $this->enrich_flag_actors( $flags );

		// Count the SAME status we listed, or has_more lies on every filter but the
		// default — the pagination equivalent of the dead-chip bug above.
		$total = Flag::count_by_status_in_space( $status, $space_id );

		return $this->paginated_response(
			$flags,
			[
				'total'    => $total,
				'space_id' => $space_id,
			]
		);
	}

	/**
	 * POST /spaces/{id}/moderation/flags/{flag_id}/resolve
	 *
	 * REST_Auth covers login + nonce at the route level. The per-space gate
	 * has to run here because it depends on the path-bound `id` plus the
	 * caller's space role — both unavailable to the route helper.
	 */
	public function resolve_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->require_view_space_queue( $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$flag_id = absint( $request->get_param( 'flag_id' ) );
		$status  = sanitize_text_field( (string) $request->get_param( 'status' ) );

		$result = Moderation_Service::resolve_flag( get_current_user_id(), $flag_id, $status );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'resolved' => true,
				'id'       => $flag_id,
				'status'   => $status,
			],
			200
		);
	}

	/**
	 * POST /spaces/{id}/moderation/(approve|spam|trash)/(post|reply)/{obj_id}
	 *
	 * Same gate pattern as resolve_flag — REST_Auth handles login/nonce,
	 * the per-space queue check runs here.
	 */
	public function act_on_object( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->require_view_space_queue( $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$obj_id = absint( $request->get_param( 'obj_id' ) );

		$result = Moderation_Service::set_object_status( get_current_user_id(), $type, $obj_id, $action );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'action'      => $action,
				'object_type' => $type,
				'id'          => $obj_id,
				'ok'          => true,
			],
			200
		);
	}
}
