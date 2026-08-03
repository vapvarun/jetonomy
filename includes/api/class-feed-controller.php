<?php
/**
 * Feed REST API controller — global cross-space home feed.
 *
 * The web app has only space-scoped lists (`/spaces/{id}/posts`); the mobile
 * home tab needs a single cross-space feed. This thin controller extends
 * Posts_Controller so the post response shape (including the 1.6.0
 * is_bookmarked / viewer_vote fields) stays defined in exactly one place.
 *
 * @package Jetonomy
 * @since   1.6.0
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Jetonomy\Models\Post;

class Feed_Controller extends Posts_Controller {

	protected $rest_base = 'feed';

	/**
	 * Register only the /feed route. Overrides the parent (which registers the
	 * full posts surface) so this controller exposes nothing else.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/feed',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				// Public-aware: logged-out callers get the public-space slice,
				// members get their full visibility set (gating is in SQL).
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => array_merge(
					$this->get_collection_params(),
					array(
						'sort'        => array(
							'type'    => 'string',
							'default' => 'hot',
							'enum'    => array( 'hot', 'new', 'top' ),
						),
						'window_days' => array(
							'type'        => 'integer',
							'default'     => 7,
							'minimum'     => 0,
							'description' => __( 'Trailing window in days for sort=top (0 = all-time).', 'jetonomy' ),
						),
					)
				),
			)
		);
	}

	/**
	 * GET /feed — paginated global feed (sort=hot|new|top), visibility-gated.
	 */
	public function list_items( $request ): WP_REST_Response {
		$pagination = $this->get_pagination( $request );
		$sort       = sanitize_key( (string) $request->get_param( 'sort' ) );
		$sort       = in_array( $sort, array( 'hot', 'new', 'top' ), true ) ? $sort : 'hot';
		$limit      = max( 1, min( 50, (int) $pagination['limit'] ) );

		// The global feed is offset-paginated (hot/top/new are not id-ordered, so an
		// id cursor cannot advance a keyset). `cursor_next` below is therefore the
		// NEXT OFFSET, and the client sends it back as `after`. Prefer `after`, fall
		// back to an explicit `offset`. Without this the controller read only offset,
		// the client only ever sent `after`, so offset stayed 0, every page returned
		// page 1, and the app looped forever on cursor_next = last post id.
		$after  = max( 0, (int) $pagination['after'] );
		$offset = $after > 0 ? $after : max( 0, (int) $pagination['offset'] );

		$result = Post::list_global_feed(
			get_current_user_id(),
			$sort,
			$limit,
			$offset,
			(int) $request->get_param( 'window_days' )
		);

		// Same two-phase batch enrichment the space list uses — author data,
		// then viewer bookmark/vote state — so prepare_post() never runs N+1.
		$posts = $this->enrich_with_author( $result['posts'] );
		$posts = $this->enrich_viewer_state( $posts );
		$items = array_map( array( $this, 'prepare_post' ), $posts );

		$total       = (int) $result['total'];
		$next_offset = $offset + count( $items );
		$response    = $this->paginated_response(
			$items,
			array(
				'total'       => $total,
				'offset'      => $offset,
				// Offset cursor, not a post id — null once the feed is exhausted so
				// the client stops instead of re-requesting the same tail forever.
				'cursor_next' => $next_offset < $total ? $next_offset : null,
			)
		);

		$response->header(
			'X-WP-TotalPages',
			(string) (int) ceil( (int) $result['total'] / max( 1, $limit ) )
		);

		return $response;
	}
}
