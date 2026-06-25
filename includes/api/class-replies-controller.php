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
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Revision;
use Jetonomy\Models\Notification;
use Jetonomy\Models\UserProfile;
use Jetonomy\Trust\Reputation;

class Replies_Controller extends Base_Controller {

	protected $rest_base = 'replies';

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
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
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
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
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
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_update_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
			)
		);

		// Accept action.
		register_rest_route(
			$ns,
			'/replies/(?P<id>\d+)/accept',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'accept_reply' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unaccept_reply' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/replies/(?P<id>\d+)/split',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'split_reply' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
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
				'total'  => Reply::count_by_post( $post_id ),
				'offset' => (int) $pagination['offset'],
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
			\Jetonomy\client_ip()
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

		// Akismet spam check — skip for site admins and space admins/moderators.
		// Staff replies should never be quarantined by the automatic filter.
		$akismet_spam = false;
		if ( ! $this->author_bypasses_spam_check( $user_id, $space_id ) ) {
			$ip           = \Jetonomy\client_ip();
			$user         = get_userdata( $user_id );
			$akismet_spam = \Jetonomy\Moderation\Akismet::check_spam(
				$content,
				$user->display_name ?? '',
				$user->user_email ?? '',
				$ip
			);
		}

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

		// Backdate via published_at — maps to created_at since jt_replies has no separate column.
		// Gated to manage_options; reply date must not precede parent post's published_at/created_at.
		$raw_published_at = $request->get_param( 'published_at' );
		$backdate         = null;
		if ( null !== $raw_published_at && '' !== $raw_published_at ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->permission_error();
			}
			$backdate = $this->sanitize_backdate( $raw_published_at );
			if ( is_wp_error( $backdate ) ) {
				return $backdate;
			}
			if ( null !== $backdate ) {
				$parent_date = $post->published_at ?? $post->created_at ?? null;
				if ( $parent_date && strtotime( $backdate ) < strtotime( $parent_date ) ) {
					return $this->validation_error( __( 'Reply published_at cannot precede the parent post date.', 'jetonomy' ) );
				}
				$reply_data['created_at'] = $backdate;
			}
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
		// 'flag' is handled AFTER Reply::create — see auto-flag block below.

		// Per-space require_approval: hold unless the author is space staff.
		if ( $this->should_hold_for_approval( (string) ( $reply_data['status'] ?? '' ), $space_id, $user_id ) ) {
			$reply_data['status'] = 'pending';
		}

		$reply_id = Reply::create( $reply_data );

		if ( is_wp_error( $reply_id ) ) {
			return $reply_id;
		}

		if ( ! $reply_id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create reply.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}

		// Increment rate limit counter.
		\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'create_replies' );

		// Auto-flag a reply when a moderation rule asked to flag the content.
		// The reply still publishes; a Flag record surfaces it in the queue.
		if ( 'flag' === $moderation_action && $reply_id > 0 ) {
			$auto_flag_id = \Jetonomy\Models\Flag::create(
				array(
					'reporter_id' => 0,
					'object_type' => 'reply',
					'object_id'   => (int) $reply_id,
					'reason'      => 'other',
					'description' => __( 'Flagged automatically by a moderation rule.', 'jetonomy' ),
				)
			);
			if ( $auto_flag_id ) {
				do_action( 'jetonomy_flag_created', (int) $auto_flag_id, 'reply' );
			}
		}

		// For backdated replies, roll the parent's last_reply_at back to the reply's
		// date so stale "just now" stamps don't surface on historical topics.
		if ( null !== $backdate ) {
			Post::update( $post_id, array( 'last_reply_at' => $backdate ) );
		}

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

		$update_data = array();

		$raw_content = $request->get_param( 'content' );
		if ( null !== $raw_content ) {
			$content = wp_kses_post( (string) $raw_content );
			if ( empty( $content ) ) {
				return $this->validation_error( __( 'Reply content is required.', 'jetonomy' ) );
			}

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

			$update_data['content']       = $content;
			$update_data['content_plain'] = wp_strip_all_tags( $content );
			$update_data['edited_at']     = current_time( 'mysql' );
			$update_data['edited_by']     = $user_id;
		}

		// Backdate: accept published_at to rewrite created_at. Gated to manage_options.
		$raw_published_at = $request->get_param( 'published_at' );
		if ( null !== $raw_published_at && '' !== $raw_published_at ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->permission_error();
			}
			$backdate = $this->sanitize_backdate( $raw_published_at );
			if ( is_wp_error( $backdate ) ) {
				return $backdate;
			}
			if ( null !== $backdate ) {
				$parent_date = $post->published_at ?? $post->created_at ?? null;
				if ( $parent_date && strtotime( $backdate ) < strtotime( $parent_date ) ) {
					return $this->validation_error( __( 'Reply published_at cannot precede the parent post date.', 'jetonomy' ) );
				}
				$update_data['created_at'] = $backdate;
			}
		}

		if ( empty( $update_data ) ) {
			return $this->validation_error( __( 'No fields provided for update.', 'jetonomy' ) );
		}

		Reply::update( $id, $update_data );

		// Keep parent's last_reply_at consistent when we backdated.
		if ( isset( $update_data['created_at'] ) ) {
			Post::update( (int) $post->id, array( 'last_reply_at' => $update_data['created_at'] ) );
		}

		do_action( 'jetonomy_reply_updated', $id, $space_id, $user_id );

		$updated = Reply::find( $id );

		/**
		 * Fires after a reply is updated with the full reply object plus context.
		 *
		 * @since 1.4.1
		 * @param object          $updated Reply object.
		 * @param array{space_id:int,user_id:int,request:WP_REST_Request} $context Context.
		 */
		do_action(
			'jetonomy_after_update_reply',
			$updated,
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'request'  => $request,
			)
		);

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

		// Reply::update() detects the publish→trash transition and decrements
		// post/user counters atomically — no manual pre-decrement needed.
		Reply::update( $id, array( 'status' => 'trash' ) );

		do_action( 'jetonomy_reply_deleted', $id, $space_id, $user_id );

		/**
		 * Fires after a reply is deleted. Receives only the deleted reply ID.
		 *
		 * @since 1.4.1
		 * @param int $id Deleted reply ID.
		 */
		do_action( 'jetonomy_after_delete_reply', $id );

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

		// Accept-answer is a Q&A workflow. Other space types use the
		// roadmap status (Ideas) or have no equivalent (Forum, Feed), so
		// accepting on them would write `is_resolved=1` data that those
		// types' read paths interpret differently. Refuse cleanly.
		$space = \Jetonomy\Models\Space::find( $space_id );
		if ( ! $space || 'qa' !== ( $space->type ?? '' ) ) {
			return new \WP_Error(
				'jetonomy_not_qa_space',
				__( 'Accepted answers only apply to Q&A spaces.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

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
			Reputation::award( $reply_author_id, 'reply_accepted' );

			// Notification handled by Notifier via jetonomy_reply_accepted hook above.
		}

		$updated_reply = Reply::find( $id );

		return new WP_REST_Response( $this->prepare_reply( $updated_reply ), 200 );
	}

	/**
	 * DELETE /replies/{id}/accept — Un-accept a reply (clear the accepted answer).
	 *
	 * Reverse of accept_reply(): the reply stops being the accepted answer, the
	 * post returns to unresolved, and the reputation awarded on acceptance is
	 * revoked so trust scores stay honest. Same gate as accepting — the post
	 * author or anyone who can close posts in the space.
	 */
	public function unaccept_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		// Only post author or a moderator/admin may un-accept (mirrors accept).
		$can_unaccept = ( $post_author_id === $user_id )
			|| $this->check_permission( 'close_posts', $space_id );
		if ( ! $can_unaccept ) {
			return $this->permission_error();
		}

		if ( empty( $reply->is_accepted ) ) {
			return new \WP_Error(
				'jetonomy_not_accepted',
				__( 'This reply is not the accepted answer.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		Reply::unmark_accepted( $id );
		Post::clear_accepted_reply( (int) $post->id );

		do_action( 'jetonomy_reply_unaccepted', $id, (int) $post->id );

		// Revoke the reputation granted on acceptance (skip self, mirroring accept).
		if ( $reply_author_id && $reply_author_id !== $user_id ) {
			Reputation::revoke( $reply_author_id, 'reply_accepted' );
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

		$data = array(
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
			// Aliased from created_at since jt_replies has no separate published_at column.
			'published_at'  => $reply->created_at ?? null,
			// Enriched author data (for app clients + JS rendering)
			'author_name'   => $author_name,
			'author_avatar' => $author_avatar,
			'author_login'  => $author_login,
			'trust_level'   => $trust_level,
			'reputation'    => $reputation,
			'time_ago'      => $reply->created_at ? human_time_diff( strtotime( $reply->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '',
			'profile_url'   => $profile_url,
		);

		/**
		 * Filter the REST response data for a single reply.
		 *
		 * @param array  $data    Prepared response data.
		 * @param object $reply   Raw reply row object.
		 * @param null   $request WP_REST_Request (null in non-request contexts).
		 */
		$data = apply_filters( 'jetonomy_rest_prepare_reply', $data, $reply, null );

		return $data;
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return array(
			'content'      => array(
				'type'     => 'string',
				'required' => true,
			),
			'parent_id'    => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 1,
			),
			'published_at' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Args for update_item.
	 */
	private function get_update_args(): array {
		return array(
			'content'      => array(
				'type'     => 'string',
				'required' => false,
			),
			'published_at' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
