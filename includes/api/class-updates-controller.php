<?php
/**
 * Real-time updates REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\ActivityLog;
use Jetonomy\Models\Reply;

class Updates_Controller extends Base_Controller {

	protected $rest_base = 'updates';

	/**
	 * Register REST routes for real-time updates polling.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/updates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_updates' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => array(
					'since' => array(
						'type'     => 'string',
						'required' => true,
					),
					'scope' => array(
						'type'    => 'string',
						'default' => 'global',
						'enum'    => array( 'global', 'space', 'post' ),
					),
					'id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * GET /updates — Lightweight polling endpoint for new activity since a timestamp.
	 *
	 * Returns `Cache-Control: no-cache` as this is per-user real-time data.
	 */
	public function get_updates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$since = $request->get_param( 'since' );
		$scope = $request->get_param( 'scope' ) ?? 'global';
		$id    = $request->get_param( 'id' ) ? absint( $request->get_param( 'id' ) ) : null;

		// Post-scope polling returns only the new-reply ID list for a single
		// post, so it follows the same visibility contract as reading the post
		// itself (Permission_Engine::can_read_post) — anonymous visitors on a
		// public community can poll posts they can read. Without this, every
		// logged-out visitor on a single-post page got a 401 each poll cycle
		// and the new-replies banner never worked for them. Global and space
		// scopes aggregate activity-log data and stay login-only.
		if ( 'post' === $scope && $id ) {
			$post = \Jetonomy\Models\Post::find( $id );
			if ( ! $post ) {
				return $this->not_found( 'Post' );
			}
			if ( ! \Jetonomy\Permissions\Permission_Engine::can_read_post( get_current_user_id(), $post ) ) {
				return $this->permission_error();
			}
		} else {
			$user_id = $this->require_auth();
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		// Normalize `since` to MySQL datetime format.
		$since_dt = $this->normalize_datetime( $since );
		if ( ! $since_dt ) {
			return $this->validation_error(
				__( 'Invalid `since` timestamp. Use ISO 8601 or MySQL datetime format.', 'jetonomy' )
			);
		}

		if ( in_array( $scope, array( 'space', 'post' ), true ) && ! $id ) {
			return $this->validation_error(
				// jt-terms-ok: literal scope parameter values for API callers.
				__( 'The `id` parameter is required for space or post scope.', 'jetonomy' )
			);
		}

		if ( 'post' === $scope ) {
			$data = Reply::ids_since( $id, $since_dt );
		} else {
			$rows = 'space' === $scope ? ActivityLog::list_since_in_space( $since_dt, $id ) : ActivityLog::list_since( $since_dt );
			$data = array_map(
				static function ( $row ) {
					return array(
						'action'      => $row->action,
						'object_type' => $row->object_type,
						'object_id'   => (int) $row->object_id,
						'created_at'  => $row->created_at,
					);
				},
				$rows
			);
		}

		$response = new WP_REST_Response(
			array(
				'data'  => $data,
				'since' => $since_dt,
				'scope' => $scope,
				'meta'  => array(
					'count'    => count( $data ),
					'has_more' => false,
				),
			),
			200
		);

		$response->header( 'Cache-Control', 'no-cache' );

		return $response;
	}

	/**
	 * Normalize an ISO 8601 or MySQL datetime string to MySQL datetime format.
	 *
	 * Returns null if the value cannot be parsed.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	private function normalize_datetime( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$value = (string) $value;

		// Already MySQL datetime format: YYYY-MM-DD HH:MM:SS.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		// Try parsing as ISO 8601 (e.g. 2026-03-18T12:00:00Z or with offset).
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
