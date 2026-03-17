<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Revision;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\UserProfile;

class Replies_Controller extends Base_Controller {

	protected string $rest_base = 'replies';

	/**
	 * Reputation delta awarded when a reply is accepted as the answer.
	 */
	private const REP_REPLY_ACCEPTED = 15;

	/**
	 * Register all REST routes for replies.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		// Collection routes nested under posts.
		register_rest_route( $ns, '/posts/(?P<post_id>\d+)/replies', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->get_collection_params(),
					[
						'post_id' => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						],
						'sort' => [
							'type'    => 'string',
							'default' => 'oldest',
							'enum'    => [ 'oldest', 'newest', 'best' ],
						],
					]
				),
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_create_args(),
			],
		] );

		// Single-item routes.
		register_rest_route( $ns, '/replies/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_update_args(),
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => '__return_true',
			],
		] );

		// Accept action.
		register_rest_route( $ns, '/replies/(?P<id>\d+)/accept', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'accept_reply' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * GET /posts/{post_id}/replies — List replies for a post.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = Post::find( $post_id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		if ( ! $this->check_permission( 'read', (int) $post->space_id ) ) {
			return $this->permission_error();
		}

		$pagination = $this->get_pagination( $request );
		// Replies default to 'oldest'; honour sort override from request.
		$sort = $request->get_param( 'sort' ) ?? 'oldest';

		$replies = Reply::list_by_post(
			$post_id,
			$sort,
			(int) $pagination['limit'],
			(int) $pagination['offset']
		);

		$items = array_map( [ $this, 'prepare_reply' ], $replies );

		return $this->paginated_response( $items, [
			'total'    => Reply::count_by_post( $post_id ),
			'has_more' => count( $items ) === (int) $pagination['limit'],
		] );
	}

	/**
	 * POST /posts/{post_id}/replies — Create a new reply.
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = Post::find( $post_id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space_id = (int) $post->space_id;

		if ( ! $this->check_permission( 'create_replies', $space_id ) ) {
			return $this->permission_error();
		}

		// Prevent replies to closed posts.
		if ( ! empty( $post->is_closed ) ) {
			return new WP_Error(
				'jetonomy_post_closed',
				__( 'This post is closed and cannot receive new replies.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		$content = wp_kses_post( (string) $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return $this->validation_error( __( 'Reply content is required.', 'jetonomy' ) );
		}

		$content_plain = wp_strip_all_tags( $content );

		$reply_data = [
			'post_id'       => $post_id,
			'author_id'     => $user_id,
			'content'       => $content,
			'content_plain' => $content_plain,
		];

		// Support threaded replies via parent_id.
		$parent_id = absint( $request->get_param( 'parent_id' ) );
		if ( $parent_id ) {
			$reply_data['parent_id'] = $parent_id;
		}

		$reply_id = Reply::create( $reply_data );

		if ( ! $reply_id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create reply.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		// Update user profile reply count.
		UserProfile::find_or_create( $user_id );
		UserProfile::increment_reply_count( $user_id );

		// Notify the post author (skip self-notification).
		$post_author_id = (int) $post->author_id;
		if ( $post_author_id && $post_author_id !== $user_id ) {
			Notification::create( [
				'user_id'     => $post_author_id,
				'type'        => 'reply',
				'object_type' => 'reply',
				'object_id'   => $reply_id,
				'actor_id'    => $user_id,
			] );
		}

		// Notify all subscribers of the post (excluding the replier and post author
		// already notified above).
		$already_notified = [ $user_id, $post_author_id ];
		$subscribers      = Subscription::get_subscribers( 'post', $post_id );

		foreach ( $subscribers as $subscriber_id ) {
			if ( in_array( $subscriber_id, $already_notified, true ) ) {
				continue;
			}

			Notification::create( [
				'user_id'     => $subscriber_id,
				'type'        => 'reply',
				'object_type' => 'reply',
				'object_id'   => $reply_id,
				'actor_id'    => $user_id,
			] );
		}

		$reply = Reply::find( $reply_id );

		return new WP_REST_Response( $this->prepare_reply( $reply ), 201 );
	}

	/**
	 * PATCH /replies/{id} — Update a reply.
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id    = absint( $request->get_param( 'id' ) );
		$reply = Reply::find( $id );

		if ( ! $reply ) {
			return $this->not_found( 'Reply' );
		}

		$post = Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space_id  = (int) $post->space_id;
		$is_author = (int) $reply->author_id === $user_id;

		$can_edit = ( $is_author && $this->check_permission( 'create_replies', $space_id ) )
			|| $this->check_permission( 'edit_others_posts', $space_id );

		if ( ! $can_edit ) {
			return $this->permission_error();
		}

		$content = wp_kses_post( (string) $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return $this->validation_error( __( 'Reply content is required.', 'jetonomy' ) );
		}

		// Create a revision before updating.
		Revision::create( [
			'object_type'    => 'reply',
			'object_id'      => $id,
			'edited_by'      => $user_id,
			'content_before' => $reply->content ?? '',
			'content_after'  => $content,
		] );

		Reply::update( $id, [
			'content'       => $content,
			'content_plain' => wp_strip_all_tags( $content ),
			'edited_at'     => current_time( 'mysql' ),
			'edited_by'     => $user_id,
		] );

		$updated = Reply::find( $id );

		return new WP_REST_Response( $this->prepare_reply( $updated ), 200 );
	}

	/**
	 * DELETE /replies/{id} — Soft-delete (trash) a reply.
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id    = absint( $request->get_param( 'id' ) );
		$reply = Reply::find( $id );

		if ( ! $reply ) {
			return $this->not_found( 'Reply' );
		}

		$post = Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space_id  = (int) $post->space_id;
		$is_author = (int) $reply->author_id === $user_id;

		$can_delete = ( $is_author && $this->check_permission( 'create_replies', $space_id ) )
			|| $this->check_permission( 'delete_others_posts', $space_id );

		if ( ! $can_delete ) {
			return $this->permission_error();
		}

		Reply::update( $id, [ 'status' => 'trash' ] );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * POST /replies/{id}/accept — Mark a reply as the accepted answer (Q&A).
	 */
	public function accept_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id    = absint( $request->get_param( 'id' ) );
		$reply = Reply::find( $id );

		if ( ! $reply ) {
			return $this->not_found( 'Reply' );
		}

		$post = Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space_id        = (int) $post->space_id;
		$post_author_id  = (int) $post->author_id;
		$reply_author_id = (int) $reply->author_id;

		// Only post author or a moderator/admin may accept a reply.
		$can_accept = ( $post_author_id === $user_id )
			|| $this->check_permission( 'close_posts', $space_id );

		if ( ! $can_accept ) {
			return $this->permission_error();
		}

		// Mark the reply as accepted and resolve the post.
		Reply::mark_accepted( $id );
		Post::accept_reply( (int) $post->id, $id );

		// Award reputation to the reply author (skip self-award).
		if ( $reply_author_id && $reply_author_id !== $user_id ) {
			UserProfile::find_or_create( $reply_author_id );
			UserProfile::adjust_reputation( $reply_author_id, self::REP_REPLY_ACCEPTED );

			// Notify the reply author that their answer was accepted.
			Notification::create( [
				'user_id'     => $reply_author_id,
				'type'        => 'reply_accepted',
				'object_type' => 'reply',
				'object_id'   => $id,
				'actor_id'    => $user_id,
			] );
		}

		$updated_reply = Reply::find( $id );

		return new WP_REST_Response( $this->prepare_reply( $updated_reply ), 200 );
	}

	/**
	 * Format a reply object for API output.
	 */
	private function prepare_reply( object $reply ): array {
		return [
			'id'            => (int) $reply->id,
			'post_id'       => (int) $reply->post_id,
			'parent_id'     => $reply->parent_id ? (int) $reply->parent_id : null,
			'author_id'     => (int) $reply->author_id,
			'content'       => $reply->content ?? '',
			'content_plain' => $reply->content_plain ?? '',
			'status'        => $reply->status ?? 'publish',
			'is_accepted'   => (bool) ( $reply->is_accepted ?? false ),
			'vote_score'    => (int) ( $reply->vote_score ?? 0 ),
			'edited_at'     => $reply->edited_at ?? null,
			'edited_by'     => $reply->edited_by ? (int) $reply->edited_by : null,
			'created_at'    => $reply->created_at ?? null,
		];
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return [
			'content'   => [ 'type' => 'string', 'required' => true ],
			'parent_id' => [ 'type' => 'integer', 'required' => false, 'minimum' => 1 ],
		];
	}

	/**
	 * Args for update_item.
	 */
	private function get_update_args(): array {
		return [
			'content' => [ 'type' => 'string', 'required' => true ],
		];
	}
}
