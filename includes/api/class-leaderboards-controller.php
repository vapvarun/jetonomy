<?php
/**
 * Leaderboards REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Leaderboards_Controller extends Base_Controller {

	protected $rest_base = 'leaderboards';

	/**
	 * Register REST routes for leaderboards.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/leaderboards',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'limit'  => [
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
					'period' => [
						'type'              => 'string',
						'default'           => 'all',
						'enum'              => [ 'all', 'month', 'week' ],
						'description'       => 'Time period: all, month, or week.',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * GET /leaderboards — Ranked list of members by reputation.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );
		$period = $request->get_param( 'period' ) ?? 'all';

		$profiles_tbl = \Jetonomy\table( 'user_profiles' );

		/**
		 * Filter user/leaderboard query parameters before execution.
		 *
		 * @param array $args Query parameters: order_by, limit, offset.
		 */
		$args = apply_filters(
			'jetonomy_users_query_args',
			array(
				'order_by' => 'reputation DESC',
				'limit'    => $limit,
				'offset'   => $offset,
			)
		);

		$order_by_sql = $args['order_by'];
		$limit        = (int) $args['limit'];
		$offset       = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$profiles_tbl}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$leaders = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$profiles_tbl} ORDER BY {$order_by_sql} LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		) ?: [];

		$settings  = get_option( 'jetonomy_settings', [] );
		$base_slug = $settings['base_slug'] ?? 'community';

		$items = [];
		$rank  = $offset + 1;

		foreach ( $leaders as $leader ) {
			$user = get_userdata( (int) $leader->user_id );
			if ( ! $user ) {
				++$rank;
				continue;
			}

			$items[] = [
				'rank'         => $rank,
				'user_id'      => (int) $leader->user_id,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'avatar_url'   => get_avatar_url( (int) $leader->user_id, [ 'size' => 64 ] ),
				'profile_url'  => \Jetonomy\get_profile_url( (int) $leader->user_id ),
				'reputation'   => (int) $leader->reputation,
				'post_count'   => (int) $leader->post_count,
				'reply_count'  => (int) $leader->reply_count,
				'trust_level'  => (int) $leader->trust_level,
			];

			++$rank;
		}

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
				'period' => $period,
			]
		);
	}
}
