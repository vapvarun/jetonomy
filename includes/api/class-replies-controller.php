<?php
/**
 * Replies REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Revision;
use Jetonomy\Models\Notification;
use Jetonomy\Models\UserProfile;

class Replies_Controller extends Base_Controller {

	protected $rest_base = 'replies';

	/**
	 * Reputation delta awarded when a reply is accepted as the answer.
	 */
	private const REP_REPLY_ACCEPTED = 15;

	/**
	 * Register all REST routes for replies.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Collection routes nested under posts.
		register_rest_route(
			$ns,
			'/posts/(?P<post_id>\d+)/replies',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_collection_params(),
						array(
							'post_id' => array(
								'type'     => 'integer',
								'required' => true,
								'minimum'  => 1,
							),
							'sort'    => array(
								'type'    => 'string',
								'default' => 'oldest',
								'enum'    => array( 'oldest', 'newest', 'best' ),
							),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => function () {
						return is_user_logged_in(); },
					'args'                => $this->get_create_args(),
				),
			)
		);

		// Single-item routes.
		register_rest_route(
			$ns,
			'/replies/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => function () {
						return is_user_logged_in(); },
					'args'                => $this->get_update_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => function () {
						return is_user_logged_in(); },
				),
			)
		);

		// Accept action.
		register_rest_route(
			$ns,
			'/replies/(?P<id>\d+)/accept',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept_reply' ),
				'permission_callback' => function () {
					return is_user_logged_in(); },
			)
		);

		register_rest_route(
			$ns,
			'/replies/(?P<id>\d+)/split',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'split_reply' ),
				'permission_callback' => function () {
					return is_user_logged_in(); },
				'args'                => array(
					'title'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'space_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * GET /posts/{post_id}/replies — List replies for a post.
	 */
	public function list_items( $request ) {
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
			(int) $pagination['offset'],
			(int) $pagination['after']
		);

		// Eager-load all author data in a single batch before preparing items.
		$replies = $this->enrich_with_author( $replies );

		$items = array_map( array( $this, 'prepare_reply' ), $replies );

		return $this->paginated_response(
			$items,
			array(
				'total'    => Reply::count_by_post( $post_id ),
				'has_more' => count( $items ) === (int) $pagination['limit'],
			)
		);
	}

	/**
	 * POST /posts/{post_id}/replies — Create a new reply.
	 */
	public function create_item( $request ) {
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

		// Block replies in archived or locked spaces.
		$space = \Jetonomy\Models\Space::find( $space_id );
		if ( $space && in_array( $space->status ?? '', array( 'archived', 'locked' ), true ) ) {
			return new WP_Error(
				'jetonomy_space_restricted',
				__( 'This space is archived or locked and no longer accepts new replies.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->check_permission( 'create_replies', $space_id ) ) {
			return $this->permission_error();
		}

		// Rate limit check.
		$profile = UserProfile::find_or_create( $user_id );
		$trust   = (int) ( $profile->trust_level ?? 0 );
		if ( ! \Jetonomy\Permissions\Rate_Limiter::check( $user_id, 'create_replies', $trust ) ) {
			return $this->validation_error( __( 'Rate limit exceeded. Please try again later.', 'jetonomy' ) );
		}

		// CAPTCHA verification (skipped for trust level 2+ users and admins).
		$captcha_token  = sanitize_text_field( (string) $request->get_param( 'captcha_token' ) );
		$captcha_result = \Jetonomy\Captcha\Captcha_Manager::verify_or_skip(
			$user_id,
			$captcha_token,
			sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) )
		);
		if ( false === $captcha_result ) {
			return $this->validation_error( __( 'Security check failed. Please refresh the page and try again.', 'jetonomy' ) );
		}

		// Prevent replies to closed posts.
		if ( ! empty( $post->is_closed ) ) {
			return new WP_Error(
				'jetonomy_post_closed',
				__( 'This post is closed and cannot receive new replies.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		$content = wp_kses_post( (string) $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return $this->validation_error( __( 'Reply content is required.', 'jetonomy' ) );
		}

		$content_plain = wp_strip_all_tags( $content );

		// Akismet spam check.
		$ip           = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user         = get_userdata( $user_id );
		$akismet_spam = \Jetonomy\Moderation\Akismet::check_spam(
			$content,
			$user->display_name ?? '',
			$user->user_email ?? '',
			$ip
		);

		$reply_data = array(
			'post_id'       => $post_id,
			'author_id'     => $user_id,
			'content'       => $content,
			'content_plain' => $content_plain,
		);

		if ( $akismet_spam ) {
			$reply_data['status'] = 'spam';
		}

		// Support threaded replies via parent_id.
		$parent_id = absint( $request->get_param( 'parent_id' ) );
		if ( $parent_id ) {
			$reply_data['parent_id'] = $parent_id;
		}

		/**
		 * Check content against moderation rules before insertion.
		 *
		 * @param string|null $action   null if no action, or 'flag', 'hold', 'block', 'spam'.
		 * @param array       $data     Reply data array with 'content' key.
		 * @param int         $space_id Space ID.
		 * @param int         $user_id  Author user ID.
		 */
		$moderation_action = apply_filters( 'jetonomy_check_content', null, $reply_data, $space_id, $user_id );

		if ( 'block' === $moderation_action ) {
			return $this->validation_error( __( 'Your reply was blocked by our content policy.', 'jetonomy' ) );
		}
		if ( 'hold' === $moderation_action ) {
			$reply_data['status'] = 'pending';
		}
		if ( 'spam' === $moderation_action ) {
			$reply_data['status'] = 'spam';
		}

		// Per-space require_approval: hold for moderation unless moderator/admin.
		if ( empty( $reply_data['status'] ) || 'publish' === ( $reply_data['status'] ?? '' ) ) {
			$space_settings = \Jetonomy\Models\Space::get_settings( $space_id );
			if ( ! empty( $space_settings['require_approval'] ) ) {
				$member_role = \Jetonomy\Models\SpaceMember::get_role( $space_id, $user_id );
				if ( ! in_array( $member_role, array( 'moderator', 'admin' ), true ) && ! current_user_can( 'manage_options' ) ) {
					$reply_data['status'] = 'pending';
				}
			}
		}

		$reply_id = Reply::create( $reply_data );

		if ( ! $reply_id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create reply.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}

		// Update user profile reply count.
		UserProfile::increment_reply_count( $user_id );

		// Increment rate limit counter.
		\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'create_replies' );

		// Fire action for Notifier and other listeners (handles all notifications).
		do_action( 'jetonomy_after_create_reply', $reply_id, $post_id );

		// Parse @mentions and notify.
		$mentioned = \Jetonomy\Mentions::extract_user_ids( $content );
		if ( ! empty( $mentioned ) ) {
			\Jetonomy\Mentions::notify( $mentioned, $user_id, 'reply', $reply_id, $post->title ?? __( 'your reply', 'jetonomy' ) );
		}

		$reply = Reply::find( $reply_id );

		return new WP_REST_Response( $this->prepare_reply( $reply ), 201 );
	}

	/**
	 * PATCH /replies/{id} — Update a reply.
	 */
	public function update_item( $request ) {
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

		// Advanced Moderation: check updated content.
		$moderation_action = apply_filters( 'jetonomy_check_content', null, array( 'content' => $content ), $space_id, $user_id );
		if ( 'block' === $moderation_action ) {
			return $this->validation_error( __( 'Your reply was blocked by our content policy.', 'jetonomy' ) );
		}

		// Create a revision before updating.
		Revision::create(
			array(
				'object_type' => 'reply',
				'object_id'   => $id,
				'author_id'   => $user_id,
				'content'     => $reply->content ?? '',
			)
		);

		Reply::update(
			$id,
			array(
				'content'       => $content,
				'content_plain' => wp_strip_all_tags( $content ),
				'edited_at'     => current_time( 'mysql' ),
				'edited_by'     => $user_id,
			)
		);

		do_action( 'jetonomy_reply_updated', $id, $space_id, $user_id );

		$updated = Reply::find( $id );

		return new WP_REST_Response( $this->prepare_reply( $updated ), 200 );
	}

	/**
	 * DELETE /replies/{id} — Soft-delete (trash) a reply.
	 */
	public function delete_item( $request ) {
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

		// Decrement denormalized counters before soft-deleting.
		Post::increment_reply_count( (int) $reply->post_id, -1 );
		UserProfile::increment_reply_count( (int) $reply->author_id, -1 );

		Reply::update( $id, array( 'status' => 'trash' ) );

		do_action( 'jetonomy_reply_deleted', $id, $space_id, $user_id );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
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

		// Fire action for Notifier and other listeners.
		do_action( 'jetonomy_reply_accepted', $id, (int) $post->id );

		// Award reputation to the reply author (skip self-award).
		if ( $reply_author_id && $reply_author_id !== $user_id ) {
			UserProfile::find_or_create( $reply_author_id );
			UserProfile::adjust_reputation( $reply_author_id, self::REP_REPLY_ACCEPTED );

			// Notification handled by Notifier via jetonomy_reply_accepted hook above.
		}

		$updated_reply = Reply::find( $id );

		return new WP_REST_Response( $this->prepare_reply( $updated_reply ), 200 );
	}

	/**
	 * POST /replies/{id}/split — Split a reply into a new topic.
	 */
	public function split_reply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$reply_id = absint( $request->get_param( 'id' ) );
		$reply    = \Jetonomy\Models\Reply::find( $reply_id );

		if ( ! $reply ) {
			return $this->not_found( 'Reply' );
		}

		$post = \Jetonomy\Models\Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		// Require moderator permission.
		if ( ! $this->check_permission( 'move_posts', (int) $post->space_id ) ) {
			return $this->permission_error();
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( empty( $title ) ) {
			return $this->validation_error( __( 'A title is required for the new topic.', 'jetonomy' ) );
		}

		$target_space_id = absint( $request->get_param( 'space_id' ) );

		// If moving to a different space, check permission there too.
		if ( $target_space_id > 0 && $target_space_id !== (int) $post->space_id ) {
			if ( ! $this->check_permission( 'move_posts', $target_space_id ) ) {
				return $this->permission_error();
			}
		}

		$new_post_id = \Jetonomy\Models\Reply::split_to_topic( $reply_id, $title, $target_space_id );

		if ( ! $new_post_id ) {
			return new \WP_Error(
				'jetonomy_split_failed',
				__( 'Failed to split reply into new topic.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}

		$new_post = \Jetonomy\Models\Post::find( $new_post_id );
		$space    = \Jetonomy\Models\Space::find( (int) $new_post->space_id );

		return new \WP_REST_Response(
			array(
				'id'         => $new_post_id,
				'title'      => $new_post->title ?? '',
				'slug'       => $new_post->slug ?? '',
				'space_slug' => $space ? $space->slug : '',
			),
			201
		);
	}

	/**
	 * Format a reply object for API output.
	 *
	 * When called after enrich_with_author() the author fields are already set
	 * on the object — individual DB/cache lookups are skipped in that case.
	 */
	private function prepare_reply( object $reply ): array {
		$author_id = (int) ( $reply->author_id ?? 0 );

		// Use pre-enriched data if present, otherwise fall back to per-item lookup.
		if ( isset( $reply->author_name ) ) {
			$author_name   = $reply->author_name;
			$author_avatar = $reply->author_avatar;
			$author_login  = $reply->author_login;
			$trust_level   = $reply->trust_level;
			$reputation    = $reply->reputation;
			$profile_url   = $reply->profile_url;
		} else {
			$author        = $author_id ? get_userdata( $author_id ) : null;
			$profile       = $author_id ? \Jetonomy\Models\UserProfile::find_by_user( $author_id ) : null;
			$author_name   = $author ? $author->display_name : __( 'Anonymous', 'jetonomy' );
			$author_avatar = $author ? get_avatar_url( $author_id, array( 'size' => 64 ) ) : '';
			$author_login  = $author ? $author->user_login : '';
			$trust_level   = $profile ? (int) $profile->trust_level : 0;
			$reputation    = $profile ? (int) $profile->reputation : 0;
			$profile_url   = $author_id ? \Jetonomy\get_profile_url( $author_id ) : '';
		}

		return array(
			'id'            => (int) $reply->id,
			'post_id'       => (int) $reply->post_id,
			'parent_id'     => $reply->parent_id ? (int) $reply->parent_id : null,
			'author_id'     => $author_id,
			'content'       => \Jetonomy\Embeds::process( $reply->content ?? '' ),
			'content_plain' => $reply->content_plain ?? '',
			'status'        => $reply->status ?? 'publish',
			'is_accepted'   => (bool) ( $reply->is_accepted ?? false ),
			'vote_score'    => (int) ( $reply->vote_score ?? 0 ),
			'edited_at'     => $reply->edited_at ?? null,
			'edited_by'     => $reply->edited_by ? (int) $reply->edited_by : null,
			'created_at'    => $reply->created_at ?? null,
			// Enriched author data (for app clients + JS rendering)
			'author_name'   => $author_name,
			'author_avatar' => $author_avatar,
			'author_login'  => $author_login,
			'trust_level'   => $trust_level,
			'reputation'    => $reputation,
			'time_ago'      => $reply->created_at ? human_time_diff( strtotime( $reply->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '',
			'profile_url'   => $profile_url,
		);
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return array(
			'content'   => array(
				'type'     => 'string',
				'required' => true,
			),
			'parent_id' => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 1,
			),
		);
	}

	/**
	 * Args for update_item.
	 */
	private function get_update_args(): array {
		return array(
			'content' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
	}
}
