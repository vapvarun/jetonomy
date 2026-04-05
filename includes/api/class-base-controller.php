<?php
/**
 * Base REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Cache;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Models\UserProfile;

abstract class Base_Controller extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'jetonomy/v1';
	}

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
		$last_item   = end( $items );
		$cursor_next = $last_item
			? ( is_object( $last_item ) ? (int) $last_item->id : (int) ( $last_item['id'] ?? 0 ) )
			: null;

		$response = new WP_REST_Response(
			[
				'data' => $items,
				'meta' => array_merge(
					[
						'count'       => count( $items ),
						'has_more'    => false,
						'cursor_next' => $cursor_next,
					],
					$meta
				),
			]
		);

		if ( isset( $meta['total'] ) ) {
			$response->header( 'X-WP-Total', (string) $meta['total'] );
		}

		return $response;
	}

	/**
	 * Get pagination params from request (supports cursor + legacy offset).
	 */
	protected function get_pagination( WP_REST_Request $request ): array {
		return [
			'limit'  => (int) ( $request->get_param( 'limit' ) ?? 20 ),
			'offset' => (int) ( $request->get_param( 'offset' ) ?? 0 ),
			'sort'   => $request->get_param( 'sort' ) ?? 'latest',
			'after'  => (int) ( $request->get_param( 'after' ) ?? 0 ),
			'before' => (int) ( $request->get_param( 'before' ) ?? 0 ),
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
	 * Permission callback: require login or return WP_Error.
	 *
	 * @return bool|\WP_Error
	 */
	protected function login_permission_check(): bool|\WP_Error {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error( 'jetonomy_unauthorized', __( 'You must be logged in.', 'jetonomy' ), [ 'status' => 401 ] );
	}

	/**
	 * Standard pagination args for route registration — supports cursor and legacy offset.
	 */
	public function get_collection_params(): array {
		return [
			'limit'  => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'after'  => [
				'type'        => 'integer',
				'default'     => 0,
				'description' => 'Return items after this ID (cursor-based pagination)',
			],
			'before' => [
				'type'        => 'integer',
				'default'     => 0,
				'description' => 'Return items before this ID',
			],
			'offset' => [
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => 'Legacy offset-based pagination (use after/before instead)',
			],
			'sort'   => [
				'type'    => 'string',
				'default' => 'latest',
				'enum'    => [ 'latest', 'popular', 'oldest', 'newest' ],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Batch / eager-loading helpers
	// -------------------------------------------------------------------------

	/**
	 * Batch-fetch WP user rows for a set of IDs, using the object cache.
	 *
	 * @param int[] $ids
	 * @return array<int, object> Keyed by user ID.
	 */
	protected function batch_load_users( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}
		$ids = array_unique( array_map( 'intval', $ids ) );

		$cached  = [];
		$missing = [];

		foreach ( $ids as $id ) {
			$user = Cache::get( "user:{$id}" );
			if ( false !== $user ) {
				$cached[ $id ] = $user;
			} else {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID IN ({$placeholders})", ...$missing ) );
			foreach ( $rows as $row ) {
				$cached[ (int) $row->ID ] = $row;
				Cache::set( "user:{$row->ID}", $row, 300 );
			}
		}

		return $cached;
	}

	/**
	 * Batch-fetch Jetonomy user-profile rows for a set of user IDs.
	 *
	 * @param int[] $ids
	 * @return array<int, object> Keyed by user_id.
	 */
	protected function batch_load_profiles( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}
		$ids = array_unique( array_map( 'intval', $ids ) );

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . \Jetonomy\table( 'user_profiles' ) . " WHERE user_id IN ({$placeholders})", ...$ids ) );

		$map = [];
		foreach ( $rows as $row ) {
			$map[ (int) $row->user_id ] = $row;
		}
		return $map;
	}

	/**
	 * Enrich a list of post/reply objects with author data in a single batch.
	 *
	 * Skips enrichment if the data already exists on the object (e.g., previously
	 * enriched items are passed to prepare_* methods individually).
	 *
	 * @param array  $items      Array of objects or associative arrays.
	 * @param string $author_key The field name that holds the author user ID.
	 * @return array The same array with author fields merged in.
	 */
	protected function enrich_with_author( array $items, string $author_key = 'author_id' ): array {
		$author_ids = array_unique(
			array_filter(
				array_map(
					function ( $item ) use ( $author_key ) {
						return is_object( $item )
							? (int) ( $item->$author_key ?? 0 )
							: (int) ( $item[ $author_key ] ?? 0 );
					},
					$items
				)
			)
		);

		$users    = $this->batch_load_users( $author_ids );
		$profiles = $this->batch_load_profiles( $author_ids );

		foreach ( $items as &$item ) {
			$uid     = is_object( $item ) ? (int) $item->$author_key : (int) $item[ $author_key ];
			$user    = $users[ $uid ] ?? null;
			$profile = $profiles[ $uid ] ?? null;

			$enrichment = [
				'author_name'   => $user ? $user->display_name : __( 'Anonymous', 'jetonomy' ),
				'author_avatar' => $user ? get_avatar_url( $uid, [ 'size' => 64 ] ) : '',
				'author_login'  => $user ? $user->user_login : '',
				'trust_level'   => $profile ? (int) $profile->trust_level : 0,
				'reputation'    => $profile ? (int) $profile->reputation : 0,
				'profile_url'   => $uid ? \Jetonomy\get_profile_url( $uid ) : '',
			];

			if ( is_object( $item ) ) {
				foreach ( $enrichment as $k => $v ) {
					$item->$k = $v;
				}
			} else {
				$item = array_merge( $item, $enrichment );
			}
		}

		return $items;
	}
}
