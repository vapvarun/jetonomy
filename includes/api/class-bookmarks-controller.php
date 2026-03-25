<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Bookmark;

class Bookmarks_Controller extends Base_Controller {

	protected $rest_base = 'bookmarks';

	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route( $ns, '/bookmarks', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'toggle_item' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( $ns, '/bookmarks/(?P<post_id>\d+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'delete_item' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * GET /bookmarks — List current user's bookmarked posts.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		$posts = Bookmark::list_by_user( $user_id, $limit, $offset );
		$total = Bookmark::count_by_user( $user_id );

		$items = array_map( function ( $post ) {
			return [
				'id'            => (int) $post->id,
				'title'         => $post->title ?? '',
				'slug'          => $post->slug ?? '',
				'space_id'      => (int) $post->space_id,
				'vote_score'    => (int) ( $post->vote_score ?? 0 ),
				'reply_count'   => (int) ( $post->reply_count ?? 0 ),
				'bookmarked_at' => $post->bookmarked_at ?? null,
				'created_at'    => $post->created_at ?? null,
			];
		}, $posts );

		return $this->paginated_response( $items, [
			'total'    => $total,
			'has_more' => count( $items ) === $limit,
		] );
	}

	/**
	 * POST /bookmarks — Toggle bookmark on a post.
	 */
	public function toggle_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id ) {
			return $this->validation_error( __( 'A valid post_id is required.', 'jetonomy' ) );
		}

		$result = Bookmark::toggle( $user_id, $post_id );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * DELETE /bookmarks/{post_id} — Remove a bookmark.
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		Bookmark::remove( $user_id, $post_id );

		return new WP_REST_Response( [ 'deleted' => true, 'post_id' => $post_id ], 200 );
	}
}
