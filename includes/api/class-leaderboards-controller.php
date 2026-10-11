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
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => array(
					'limit'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'period' => array(
						'type'              => 'string',
						'default'           => 'all',
						'enum'              => array( 'all', 'month', 'week' ),
						'description'       => 'Time period: all, month, or week.',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * GET /leaderboards — Ranked list of members by reputation.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );
		$period = $request->get_param( 'period' ) ?? 'all';
		if ( ! in_array( $period, array( 'all', 'month', 'week' ), true ) ) {
			$period = 'all';
		}

		/**
		 * Filter user/leaderboard query parameters before execution.
		 *
		 * @param array $args Query parameters: order_by, limit, offset.
		 */
		$args = apply_filters(
			'jetonomy_users_query_args',
			array(
				// Same default as UserProfile::list_for_leaderboard(). The
				// user_id tiebreaker is load-bearing under LIMIT/OFFSET: without
				// it MySQL may order tied rows differently per page, so a member
				// tied on reputation can show up on two pages or on none.
				'order_by' => 'reputation DESC, user_id ASC',
				'limit'    => $limit,
				'offset'   => $offset,
			)
		);

		$order_by_sql = $args['order_by'];
		$limit        = (int) $args['limit'];
		$offset       = (int) $args['offset'];

		// Cached 300s per page — the board is fully shared by
		// design (deliberately not per-viewer filtered) and changes only on
		// reputation events; a 5-minute-stale ranking is invisible (TTL-only).
		// Skipped entirely when a third party filters the QUERY SHAPE via
		// jetonomy_users_query_args — the key cannot model an arbitrary
		// filter (same rule as the category tree). The per-request
		// jetonomy_leaderboard_items enrichment filter below runs on every
		// response, cached or not, so host-plugin rows never freeze.
		$lb_cacheable = ! has_filter( 'jetonomy_users_query_args' );
		// "v2" key: the board excludes reputation <= 0, and TTL-only keys have no bust path,
		// so bumping the key is how an upgraded site stops serving a stale page and total.
		$lb_cache_key = "lb:v2:{$period}:{$limit}:{$offset}";
		if ( $lb_cacheable ) {
			$cached = \Jetonomy\Cache::get( $lb_cache_key );
			if ( is_array( $cached ) && isset( $cached['items'], $cached['total'] ) ) {
				$items = (array) apply_filters( 'jetonomy_leaderboard_items', $cached['items'], $request );

				return $this->paginated_response(
					$items,
					array(
						'total'  => (int) $cached['total'],
						'offset' => $offset,
						'period' => $period,
					)
				);
			}
		}

		// Total + page slice come from the shared model methods so this endpoint
		// and the server-rendered leaderboard view stay in lockstep on both the
		// population (period filter) and the ordering. The model owns the period filter.
		// Deliberately NOT block-filtered — a ranking, not a content feed. See
		// UserProfile::list_for_leaderboard().
		$total   = \Jetonomy\Models\UserProfile::count_for_leaderboard( $period );
		$leaders = \Jetonomy\Models\UserProfile::list_for_leaderboard( $period, $limit, $offset, $order_by_sql );

		$base_slug = \Jetonomy\base_slug();

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
				array(
					'include' => $user_ids,
					'orderby' => 'include',
				)
			)
			: array();
		$by_id    = array();
		foreach ( $users as $u ) {
			$by_id[ (int) $u->ID ] = $u;
		}

		// Warm the profile cache in one query so the per-row Avatar::display_url()
		// below (-> UserProfile::find_by_user()) does not miss cold on each leader.
		if ( ! empty( $user_ids ) ) {
			\Jetonomy\Models\UserProfile::prime( $user_ids );
		}

		// COMPETITION ranks, from the one place that defines them, so this
		// endpoint cannot contradict the web board or the "Your rank #N" badge.
		// Tied members share a rank (2 and 2, not 2 and 3), matching the web board.
		$ranks = \Jetonomy\Models\UserProfile::competition_ranks( $leaders, $period, $offset );

		$items = array();

		foreach ( array_values( $leaders ) as $lb_index => $leader ) {
			$user_id = (int) $leader->user_id;
			$user    = $by_id[ $user_id ] ?? null;
			if ( ! $user ) {
				// No ++$rank here: a skipped row must not consume a number. The
				// positional counter had to, which is how the web board grew a
				// visible hole in its sequence before it moved to real ranks.
				continue;
			}

			$rank = $ranks[ $lb_index ] ?? ( $offset + $lb_index + 1 );

			// Presence (profiles were primed above, so this is a cache hit, not an
			// N+1). Note: the board is cached 300s, so the dot can lag presence by
			// up to the same 5-minute window it represents — it only ever shows an
			// online member as offline for a few minutes, never the reverse.
			$lb_profile = \Jetonomy\Models\UserProfile::find_by_user( $user_id );

			$items[] = array(
				'rank'             => $rank,
				'user_id'          => $user_id,
				'display_name'     => \Jetonomy\user_display_name( $user ),
				'user_login'       => $user->user_login,
				'avatar_url'       => \Jetonomy\Avatar::display_url( $user_id, 64 ),
				'profile_url'      => \Jetonomy\get_profile_url( $user_id ),
				'reputation'       => (int) $leader->reputation,
				'post_count'       => (int) $leader->post_count,
				'reply_count'      => (int) $leader->reply_count,
				'trust_level'      => (int) $leader->trust_level,
				'last_seen_at'     => $lb_profile ? $lb_profile->last_seen_at : null,
				'last_seen_at_gmt' => \Jetonomy\to_iso8601_z( $lb_profile ? $lb_profile->last_seen_at : null ),
			);
		}

		// Cache the UNFILTERED rows — the enrichment filter
		// below is per-request by contract and must never be frozen.
		if ( $lb_cacheable ) {
			\Jetonomy\Cache::set(
				$lb_cache_key,
				array(
					'items' => $items,
					'total' => $total,
				),
				300
			);
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
			array(
				'total'  => $total,
				'offset' => $offset,
				'period' => $period,
			)
		);
	}
}
