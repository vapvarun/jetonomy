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
use function Jetonomy\table;

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
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_updates' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'since' => [
						'type'     => 'string',
						'required' => true,
					],
					'scope' => [
						'type'    => 'string',
						'default' => 'global',
						'enum'    => [ 'global', 'space', 'post' ],
					],
					'id'    => [
						'type'    => 'integer',
						'minimum' => 1,
					],
				],
			]
		);
	}

	/**
	 * GET /updates — Lightweight polling endpoint for new activity since a timestamp.
	 *
	 * Returns `Cache-Control: no-cache` as this is per-user real-time data.
	 */
	public function get_updates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$since = $request->get_param( 'since' );
		$scope = $request->get_param( 'scope' ) ?? 'global';
		$id    = $request->get_param( 'id' ) ? absint( $request->get_param( 'id' ) ) : null;

		// Normalize `since` to MySQL datetime format.
		$since_dt = $this->normalize_datetime( $since );
		if ( ! $since_dt ) {
			return $this->validation_error(
				__( 'Invalid `since` timestamp. Use ISO 8601 or MySQL datetime format.', 'jetonomy' )
			);
		}

		if ( in_array( $scope, [ 'space', 'post' ], true ) && ! $id ) {
			return $this->validation_error(
				__( 'The `id` parameter is required for space or post scope.', 'jetonomy' )
			);
		}

		global $wpdb;

		if ( 'post' === $scope ) {
			$data = $this->get_post_updates( $wpdb, $id, $since_dt );
		} elseif ( 'space' === $scope ) {
			$data = $this->get_space_updates( $wpdb, $id, $since_dt );
		} else {
			$data = $this->get_global_updates( $wpdb, $since_dt );
		}

		$response = new WP_REST_Response(
			[
				'data'  => $data,
				'since' => $since_dt,
				'scope' => $scope,
				'meta'  => [
					'count'    => count( $data ),
					'has_more' => false,
				],
			],
			200
		);

		$response->header( 'Cache-Control', 'no-cache' );

		return $response;
	}

	/**
	 * Query global activity log since a datetime.
	 *
	 * @param \wpdb  $wpdb
	 * @param string $since MySQL datetime string.
	 * @return array
	 */
	private function get_global_updates( \wpdb $wpdb, string $since ): array {
		$log_table = table( 'activity_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, object_type, object_id, created_at FROM {$log_table} WHERE created_at > %s ORDER BY created_at DESC LIMIT 50",
				$since
			)
		) ?: [];

		return array_map(
			function ( $row ) {
				return [
					'action'      => $row->action,
					'object_type' => $row->object_type,
					'object_id'   => (int) $row->object_id,
					'created_at'  => $row->created_at,
				];
			},
			$rows
		);
	}

	/**
	 * Query activity log for objects within a specific space.
	 *
	 * Uses a subquery to find post IDs belonging to the space, then filters
	 * activity log entries for those objects. No JOINs — separate queries.
	 *
	 * @param \wpdb  $wpdb
	 * @param int    $space_id
	 * @param string $since MySQL datetime string.
	 * @return array
	 */
	private function get_space_updates( \wpdb $wpdb, int $space_id, string $since ): array {
		$log_table   = table( 'activity_log' );
		$posts_table = table( 'posts' );

		// Fetch post IDs in this space efficiently (no JOIN on activity_log).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$posts_table} WHERE space_id = %d",
				$space_id
			)
		);

		if ( empty( $post_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, object_type, object_id, created_at FROM {$log_table} WHERE created_at > %s AND ( (object_type = 'post' AND object_id IN ({$placeholders})) OR (object_type = 'reply' AND object_id IN ({$placeholders})) ) ORDER BY created_at DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( [ $since ], $post_ids, $post_ids )
			)
		) ?: [];

		return array_map(
			function ( $row ) {
				return [
					'action'      => $row->action,
					'object_type' => $row->object_type,
					'object_id'   => (int) $row->object_id,
					'created_at'  => $row->created_at,
				];
			},
			$rows
		);
	}

	/**
	 * Query new replies for a specific post since a datetime.
	 *
	 * @param \wpdb  $wpdb
	 * @param int    $post_id
	 * @param string $since MySQL datetime string.
	 * @return array Array of reply IDs.
	 */
	private function get_post_updates( \wpdb $wpdb, int $post_id, string $since ): array {
		$replies_table = table( 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$replies_table} WHERE post_id = %d AND created_at > %s ORDER BY created_at ASC",
				$post_id,
				$since
			)
		);

		return array_map( 'intval', $ids ?: [] );
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
