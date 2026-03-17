<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Models\UserProfile;

abstract class Base_Controller extends WP_REST_Controller {

	protected string $namespace = 'jetonomy/v1';

	/**
	 * Check if current user can perform action in space.
	 */
	protected function check_permission( string $action, ?int $space_id = null ): bool {
		return Permission_Engine::can( get_current_user_id(), $action, $space_id );
	}

	/**
	 * Standard permission denied error.
	 */
	protected function permission_error(): WP_Error {
		return new WP_Error(
			'jetonomy_forbidden',
			__( 'You do not have permission to perform this action.', 'jetonomy' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Standard not found error.
	 */
	protected function not_found( string $what = 'Resource' ): WP_Error {
		return new WP_Error(
			'jetonomy_not_found',
			sprintf( __( '%s not found.', 'jetonomy' ), $what ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Standard validation error.
	 */
	protected function validation_error( string $message ): WP_Error {
		return new WP_Error(
			'jetonomy_validation',
			$message,
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build a paginated response with cursor support.
	 */
	protected function paginated_response( array $items, array $meta = [] ): WP_REST_Response {
		$response = new WP_REST_Response( [
			'data' => $items,
			'meta' => array_merge( [
				'count'   => count( $items ),
				'has_more' => false,
			], $meta ),
		] );

		if ( isset( $meta['total'] ) ) {
			$response->header( 'X-WP-Total', (string) $meta['total'] );
		}

		return $response;
	}

	/**
	 * Get pagination params from request.
	 */
	protected function get_pagination( WP_REST_Request $request ): array {
		return [
			'limit'  => $request->get_param( 'limit' ) ?? 20,
			'offset' => $request->get_param( 'offset' ) ?? 0,
			'sort'   => $request->get_param( 'sort' ) ?? 'latest',
		];
	}

	/**
	 * Get current user ID or return error if not logged in.
	 */
	protected function require_auth(): int|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'jetonomy_unauthorized',
				__( 'You must be logged in.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}
		return $user_id;
	}

	/**
	 * Standard pagination args for route registration.
	 */
	protected function get_collection_params(): array {
		return [
			'limit' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'offset' => [
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			],
			'sort' => [
				'type'    => 'string',
				'default' => 'latest',
				'enum'    => [ 'latest', 'popular', 'oldest', 'newest' ],
			],
		];
	}
}
