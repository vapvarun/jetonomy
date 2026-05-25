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
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
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
		if ( ! in_array( $period, array( 'all', 'month', 'week' ), true ) ) {
			$period = 'all';
		}

		$profiles_tbl = \Jetonomy\table( 'user_profiles' );

		// Period filter on recent activity, matching the server-rendered
		// leaderboard template. Previously the REST endpoint accepted `period`
		// and echoed it back but never applied it, so it always returned the
		// all-time board.
		$period_where = '';
		if ( 'week' === $period ) {
			$period_where = ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
		} elseif ( 'month' === $period ) {
			$period_where = ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 30 DAY)';
		}

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
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$profiles_tbl}{$period_where}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$leaders = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$profiles_tbl}{$period_where} ORDER BY {$order_by_sql} LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		) ?: [];

		$settings  = get_option( 'jetonomy_settings', [] );
		$base_slug = $settings['base_slug'] ?? 'community';

		// Batch-fetch all leader users in one query to eliminate the
		// per-row get_userdata() N+1. get_users() also primes the user
		// meta cache so the subsequent get_avatar_url() reads hit cache
		// instead of issuing a fresh meta query for each leader.
		$user_ids = array_map(
			static fn( $r ) => (int) $r->user_id,
			$leaders
		);
		$users    = ! empty( $user_ids )
			? get_users(
				[
					'include' => $user_ids,
					'orderby' => 'include',
				]
			)
			: [];
		$by_id    = [];
		foreach ( $users as $u ) {
			$by_id[ (int) $u->ID ] = $u;
		}

		$items = [];
		$rank  = $offset + 1;

		foreach ( $leaders as $leader ) {
			$user_id = (int) $leader->user_id;
			$user    = $by_id[ $user_id ] ?? null;
			if ( ! $user ) {
				++$rank;
				continue;
			}

			$items[] = [
				'rank'         => $rank,
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'avatar_url'   => get_avatar_url( $user_id, [ 'size' => 64 ] ),
				'profile_url'  => \Jetonomy\get_profile_url( $user_id ),
				'reputation'   => (int) $leader->reputation,
				'post_count'   => (int) $leader->post_count,
				'reply_count'  => (int) $leader->reply_count,
				'trust_level'  => (int) $leader->trust_level,
			];

			++$rank;
		}

		/**
		 * Filter the leaderboard response rows before they are paginated.
		 *
		 * Lets host plugins enrich each row with cross-engine totals (badge
		 * count, level name, alternate currency) without a second REST
		 * round-trip. Reorder, prune, or add keys as needed — the contract
		 * with paginated_response is just that $items is an array of arrays.
		 *
		 * @param array            $items   Leaderboard rows.
		 * @param WP_REST_Request  $request Original REST request.
		 */
		$items = (array) apply_filters( 'jetonomy_leaderboard_items', $items, $request );

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
