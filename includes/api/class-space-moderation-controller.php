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
					'id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
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
				'permission_callback' => [ $this, 'require_view_space_queue' ],
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
				'permission_callback' => [ $this, 'require_view_space_queue' ],
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
		$flags    = Moderation_Service::list_pending_flags( get_current_user_id(), $space_id );

		return new WP_REST_Response(
			[
				'data' => $flags,
				'meta' => [
					'count'    => count( $flags ),
					'space_id' => $space_id,
				],
			],
			200
		);
	}

	/**
	 * POST /spaces/{id}/moderation/flags/{flag_id}/resolve
	 */
	public function resolve_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
	 */
	public function act_on_object( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
