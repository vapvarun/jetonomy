<?php
/**
 * User-blocking REST API controller.
 *
 * Manages a user's own block list. Read-surface filtering is applied where it
 * matters most for 1.8.0: space/feed LISTINGS and search exclude blocked authors
 * (BlockedUser::exclusion_sql), and the REST reply list tombstones them without
 * dropping the innocent replies nested beneath. NOT yet masked: the direct
 * single-post view of a blocked author's own topic (navigating straight to their
 * post URL still renders it) — a deliberate follow-up, tracked separately.
 *
 * @package Jetonomy
 * @since   1.7.1
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\BlockedUser;

class Blocks_Controller extends Base_Controller {

	protected $rest_base = 'blocks';

	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/users/me/blocks',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => function () {
						return is_user_logged_in();
					},
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				],
			]
		);

		register_rest_route(
			$ns,
			'/users/me/blocks/(?P<user_id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			]
		);
	}

	/**
	 * GET /users/me/blocks — List the current user's blocked users.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		$rows  = BlockedUser::list_by_blocker( $user_id, $limit, $offset );
		$total = BlockedUser::count_by_blocker( $user_id );

		$blocked_ids = array_map(
			static function ( $row ) {
				return (int) $row->blocked_id;
			},
			$rows
		);
		$users       = $this->batch_load_users( $blocked_ids );

		$items = array_map(
			function ( $row ) use ( $users ) {
				$blocked_id = (int) $row->blocked_id;
				$wp_user    = $users[ $blocked_id ] ?? null;

				return [
					'user_id'      => $blocked_id,
					'user_login'   => $wp_user->user_login ?? '',
					'display_name' => $wp_user->display_name ?? '',
					'avatar_url'   => \Jetonomy\Avatar::display_url( $blocked_id, 64 ),
					'created_at'   => $row->created_at ?? null,
				];
			},
			$rows
		);

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
		);
	}

	/**
	 * POST /users/me/blocks — Block a user. Body: { user_id: int }.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$blocked_id = absint( $request->get_param( 'user_id' ) );
		if ( ! $blocked_id ) {
			return $this->validation_error( __( 'A valid user_id is required.', 'jetonomy' ) );
		}

		$result = BlockedUser::block( $user_id, $blocked_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'blocked' => true,
				'user_id' => $blocked_id,
			],
			200
		);
	}

	/**
	 * DELETE /users/me/blocks/{user_id} — Unblock a user.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$blocked_id = absint( $request->get_param( 'user_id' ) );
		BlockedUser::unblock( $user_id, $blocked_id );

		return new WP_REST_Response(
			[
				'deleted' => true,
				'user_id' => $blocked_id,
			],
			200
		);
	}
}
