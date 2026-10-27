<?php
/**
 * Registers Jetonomy abilities via the WordPress Abilities API (6.9+).
 *
 * Exposes all core forum features as discoverable, executable abilities
 * so that 3rd-party AI agents can find and operate the community.
 *
 * @package Jetonomy
 * @since   1.0.0
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\Category;
use Jetonomy\Models\Vote;
use Jetonomy\Models\Tag;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Flag;
use Jetonomy\Models\ActivityLog;
use Jetonomy\Permissions\Permission_Engine;
use WP_Error;

class Abilities {

	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/*
	──────────────────────────────────────────────
	 *  Categories
	 * ──────────────────────────────────────────────*/

	public function register_categories(): void {
		wp_register_ability_category(
			'jetonomy-content',
			array(
				'label'       => __( 'Forum Content', 'jetonomy' ),
				'description' => __( 'Create, read, and manage forum posts and replies.', 'jetonomy' ),
			)
		);

		wp_register_ability_category(
			'jetonomy-spaces',
			array(
				'label'       => __( 'Community Spaces', 'jetonomy' ),
				'description' => __( 'Manage community spaces (forums, Q&A boards, idea boards).', 'jetonomy' ),
			)
		);

		wp_register_ability_category(
			'jetonomy-users',
			array(
				'label'       => __( 'Community Users', 'jetonomy' ),
				'description' => __( 'User profiles, notifications, and subscriptions.', 'jetonomy' ),
			)
		);

		wp_register_ability_category(
			'jetonomy-moderation',
			array(
				'label'       => __( 'Content Moderation', 'jetonomy' ),
				'description' => __( 'Flag, review, and moderate community content.', 'jetonomy' ),
			)
		);

		wp_register_ability_category(
			'jetonomy-search',
			array(
				'label'       => __( 'Community Search', 'jetonomy' ),
				'description' => __( 'Search posts, spaces, users, and tags across the community.', 'jetonomy' ),
			)
		);
	}

	/*
	──────────────────────────────────────────────
	 *  Abilities
	 * ──────────────────────────────────────────────*/

	public function register_abilities(): void {
		$this->register_content_abilities();
		$this->register_space_abilities();
		$this->register_user_abilities();
		$this->register_moderation_abilities();
		$this->register_search_abilities();
	}

	/* ── Content ─────────────────────────────────── */

	private function register_content_abilities(): void {

		// ── Create Post ─────────────────────────────
		wp_register_ability(
			'jetonomy/create-post',
			array(
				'label'               => __( 'Create Post', 'jetonomy' ),
				'description'         => __( 'Create a new topic, question, or discussion post in a community space. Supports tags and auto-subscribes the author.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'space_id'   => array(
							'type'        => 'integer',
							'description' => 'Target space ID.',
							'required'    => true,
						),
						'title'      => array(
							'type'        => 'string',
							'description' => 'Post title.',
							'required'    => true,
							'minLength'   => 1,
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Post body (HTML).',
							'required'    => true,
							'minLength'   => 1,
						),
						'type'       => array(
							'type'        => 'string',
							'description' => 'Post type.',
							'enum'        => array( 'topic', 'question', 'discussion', 'announcement' ),
						),
						'tags'       => array(
							'type'        => 'array',
							'description' => 'Tag names to attach.',
							'items'       => array( 'type' => 'string' ),
						),
						'is_private' => array(
							'type'        => 'boolean',
							'description' => 'Mark post as private (only author + moderators can see it).',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => 'Created post ID.',
						),
						'title'      => array( 'type' => 'string' ),
						'is_private' => array(
							'type'        => 'boolean',
							'description' => 'Whether the post is private.',
						),
						'url'        => array(
							'type'        => 'string',
							'description' => 'Permalink to the post.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_create_post' ),
				'permission_callback' => function ( $input ) {
					$space_id = (int) $input['space_id'];

					// REST_Posts_Controller::create_item() refuses archived and
					// locked spaces; Permission_Engine::can() does not look at
					// space status, so this path could start a topic in an
					// archived space (same audit as create-reply above).
					$open = \Jetonomy\Permissions\Content_Gate::space_accepts_content( $space_id );
					if ( is_wp_error( $open ) ) {
						return $open;
					}

					return $this->check_auth_and_permission( 'create_posts', $space_id );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Get Post ────────────────────────────────
		wp_register_ability(
			'jetonomy/get-post',
			array(
				'label'               => __( 'Get Post', 'jetonomy' ),
				'description'         => __( 'Retrieve a single forum post by ID, including its author, vote score, reply count, and metadata.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'content'     => array( 'type' => 'string' ),
						'author_name' => array( 'type' => 'string' ),
						'vote_score'  => array( 'type' => 'integer' ),
						'reply_count' => array( 'type' => 'integer' ),
						'status'      => array( 'type' => 'string' ),
						'created_at'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_post' ),
				// Post-scoped, not space-scoped: check_permission_or_public()
				// only asks "can you read this SPACE", which returns true for
				// any guest on a public space and says nothing about the post's
				// status or is_private. This route reads one post by id, so it
				// gates on the post.
				'permission_callback' => array( $this, 'check_read_post' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── List Posts ──────────────────────────────
		wp_register_ability(
			'jetonomy/list-posts',
			array(
				'label'               => __( 'List Posts', 'jetonomy' ),
				'description'         => __( 'List posts in a community space with pagination. Returns titles, authors, scores, and reply counts.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'space_id' => array(
							'type'        => 'integer',
							'description' => 'Space ID.',
							'required'    => true,
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Results per page (max 50).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'after'    => array(
							'type'        => 'integer',
							'description' => 'Cursor: post ID to start after (for pagination).',
							'default'     => 0,
						),
						'sort'     => array(
							'type'        => 'string',
							'description' => 'Sort order.',
							'enum'        => array( 'latest', 'top', 'active' ),
							'default'     => 'latest',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'posts'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'has_more' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_posts' ),
				'permission_callback' => function ( $input ) {
					return $this->check_permission_or_public( 'read', (int) $input['space_id'] );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Create Reply ────────────────────────────
		wp_register_ability(
			'jetonomy/create-reply',
			array(
				'label'               => __( 'Create Reply', 'jetonomy' ),
				'description'         => __( 'Reply to an existing forum post. Supports threaded replies up to 3 levels via parent_id.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => 'Post to reply to.',
							'required'    => true,
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'Reply body (HTML).',
							'required'    => true,
							'minLength'   => 1,
						),
						'parent_id' => array(
							'type'        => 'integer',
							'description' => 'Parent reply ID for threads.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Created reply ID.',
						),
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_create_reply' ),
				'permission_callback' => function ( $input ) {
					$post = Post::find( (int) $input['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ) );
					}

					/*
					 * The same gate REST and the inbound-email writer use.
					 *
					 * This callback had its own copy of the rules and the copy
					 * was short one: it checked is_closed but not whether the
					 * SPACE was archived or locked, so this ability could reply
					 * into an archived space that REST refuses with
					 * jetonomy_space_restricted. Without this gate the ability
					 * would create the reply in the same space for the same
					 * member that REST answers with 403.
					 */
					return \Jetonomy\Permissions\Content_Gate::check( get_current_user_id(), $post, (int) ( $input['parent_id'] ?? 0 ) );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── List Replies ────────────────────────────
		wp_register_ability(
			'jetonomy/list-replies',
			array(
				'label'               => __( 'List Replies', 'jetonomy' ),
				'description'         => __( 'List replies for a forum post with pagination. Includes author info, vote scores, and thread structure.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
							'required'    => true,
						),
						'limit'   => array(
							'type'        => 'integer',
							'description' => 'Results per page (max 50).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'after'   => array(
							'type'        => 'integer',
							'description' => 'Cursor: reply ID to start after.',
							'default'     => 0,
						),
						'sort'    => array(
							'type'        => 'string',
							'description' => 'Sort order.',
							'enum'        => array( 'oldest', 'newest', 'best' ),
							'default'     => 'oldest',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'replies'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'has_more' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_replies' ),
				// Gate on the PARENT post, not just its space: the replies are
				// themselves status-filtered and block-tombstoned by
				// Reply::list_by_post(), but a space-level check let the whole
				// conversation under a trashed or pending post stay readable.
				'permission_callback' => array( $this, 'check_read_post' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Vote ────────────────────────────────────
		wp_register_ability(
			'jetonomy/vote',
			array(
				'label'               => __( 'Vote on Content', 'jetonomy' ),
				'description'         => __( 'Upvote or downvote a post or reply. Toggles if voting the same direction again.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'object_type' => array(
							'type'        => 'string',
							'description' => 'Content type.',
							'enum'        => array( 'post', 'reply' ),
							'required'    => true,
						),
						'object_id'   => array(
							'type'        => 'integer',
							'description' => 'Content ID.',
							'required'    => true,
						),
						'value'       => array(
							'type'        => 'integer',
							'description' => '1 for upvote, -1 for downvote.',
							'enum'        => array( 1, -1 ),
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'vote_score' => array(
							'type'        => 'integer',
							'description' => 'New total score.',
						),
						'user_vote'  => array(
							'type'        => 'integer',
							'description' => 'Current user vote (1, -1, or 0).',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_vote' ),
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return new WP_Error( 'jetonomy_auth_required', __( 'Authentication required.', 'jetonomy' ) );
					}
					return Permission_Engine::can( $user_id, 'vote' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/* ── Spaces ──────────────────────────────────── */

	private function register_space_abilities(): void {

		// ── List Spaces ─────────────────────────────
		wp_register_ability(
			'jetonomy/list-spaces',
			array(
				'label'               => __( 'List Spaces', 'jetonomy' ),
				'description'         => __( 'List all community spaces (forums, Q&A boards, idea boards) the current user can access, grouped by category.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category_id' => array(
							'type'        => 'integer',
							'description' => 'Filter by category ID.',
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array( 'type' => 'integer' ),
							'title'        => array( 'type' => 'string' ),
							'slug'         => array( 'type' => 'string' ),
							'type'         => array( 'type' => 'string' ),
							'post_count'   => array( 'type' => 'integer' ),
							'member_count' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_spaces' ),
				'permission_callback' => array( '\\Jetonomy\\Visibility', 'can_view_community' ), // Private mode: no guest reads.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Get Space ───────────────────────────────
		wp_register_ability(
			'jetonomy/get-space',
			array(
				'label'               => __( 'Get Space Details', 'jetonomy' ),
				'description'         => __( 'Retrieve detailed information about a community space including description, rules, member count, and settings.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'space_id' => array(
							'type'        => 'integer',
							'description' => 'Space ID.',
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'title'        => array( 'type' => 'string' ),
						'description'  => array( 'type' => 'string' ),
						'type'         => array( 'type' => 'string' ),
						'visibility'   => array( 'type' => 'string' ),
						'post_count'   => array( 'type' => 'integer' ),
						'member_count' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_space' ),
				'permission_callback' => function ( $input ) {
					return $this->check_permission_or_public( 'read', (int) $input['space_id'] );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Join Space ──────────────────────────────
		wp_register_ability(
			'jetonomy/join-space',
			array(
				'label'               => __( 'Join Space', 'jetonomy' ),
				'description'         => __( 'Join a community space as a member. For private spaces, this submits a join request for moderator approval.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'space_id' => array(
							'type'        => 'integer',
							'description' => 'Space to join.',
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'joined or pending_approval.',
							'enum'        => array( 'joined', 'pending_approval' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_join_space' ),
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── List Space Members ──────────────────────
		wp_register_ability(
			'jetonomy/list-space-members',
			array(
				'label'               => __( 'List Space Members', 'jetonomy' ),
				'description'         => __( 'List members of a community space with their roles, trust levels, and reputation.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'space_id' => array(
							'type'        => 'integer',
							'description' => 'Space ID.',
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'      => array( 'type' => 'integer' ),
							'display_name' => array( 'type' => 'string' ),
							'role'         => array( 'type' => 'string' ),
							'trust_level'  => array( 'type' => 'integer' ),
							'reputation'   => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_space_members' ),
				'permission_callback' => function ( $input ) {
					return $this->check_permission_or_public( 'read', (int) $input['space_id'] );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Create Space (Admin) ────────────────────
		wp_register_ability(
			'jetonomy/create-space',
			array(
				'label'               => __( 'Create Space', 'jetonomy' ),
				'description'         => __( 'Create a new community space (forum, Q&A, ideas, or social). Requires administrator or jetonomy_manage_settings capability.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'title'       => array(
							'type'        => 'string',
							'description' => 'Space name.',
							'required'    => true,
							'minLength'   => 1,
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Space description.',
						),
						'type'        => array(
							'type'        => 'string',
							'description' => 'Space type.',
							'enum'        => Space::valid_types(),
							'default'     => 'forum',
						),
						'visibility'  => array(
							'type'        => 'string',
							'description' => 'Visibility level.',
							'enum'        => \Jetonomy\Models\Space::visibility_values(),
							'default'     => 'public',
						),
						'category_id' => array(
							'type'        => 'integer',
							'description' => 'Parent category ID.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'Created space ID.',
						),
						'title' => array( 'type' => 'string' ),
						'slug'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_create_space' ),
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_manage_settings' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/* ── Users ───────────────────────────────────── */

	private function register_user_abilities(): void {

		// ── Get User Profile ────────────────────────
		wp_register_ability(
			'jetonomy/get-user-profile',
			array(
				'label'               => __( 'Get User Profile', 'jetonomy' ),
				'description'         => __( 'Retrieve a community member\'s profile including bio, trust level, reputation, post/reply counts, and badges.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => 'WordPress user ID.',
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'user_id'      => array( 'type' => 'integer' ),
						'display_name' => array( 'type' => 'string' ),
						'bio'          => array( 'type' => 'string' ),
						'trust_level'  => array( 'type' => 'integer' ),
						'reputation'   => array( 'type' => 'integer' ),
						'post_count'   => array( 'type' => 'integer' ),
						'reply_count'  => array( 'type' => 'integer' ),
						'joined_at'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_user_profile' ),
				'permission_callback' => array( '\\Jetonomy\\Visibility', 'can_view_community' ), // Private mode: no guest reads.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── List Notifications ──────────────────────
		wp_register_ability(
			'jetonomy/list-notifications',
			array(
				'label'               => __( 'List Notifications', 'jetonomy' ),
				'description'         => __( 'List the current user\'s community notifications (replies, mentions, votes, badges) with read/unread status.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'unread_only' => array(
							'type'        => 'boolean',
							'description' => 'Only return unread notifications.',
							'default'     => false,
						),
						'limit'       => array(
							'type'        => 'integer',
							'description' => 'Results per page.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'id'         => array( 'type' => 'integer' ),
							'type'       => array( 'type' => 'string' ),
							'message'    => array( 'type' => 'string' ),
							'is_read'    => array( 'type' => 'boolean' ),
							'created_at' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_notifications' ),
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Mark Notifications Read ─────────────────
		wp_register_ability(
			'jetonomy/mark-notifications-read',
			array(
				'label'               => __( 'Mark Notifications Read', 'jetonomy' ),
				'description'         => __( 'Mark one or all of the current user\'s notifications as read.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'notification_id' => array(
							'type'        => 'integer',
							'description' => 'Specific notification ID. Omit to mark all as read.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'marked' => array(
							'type'        => 'integer',
							'description' => 'Number of notifications marked read.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_mark_notifications_read' ),
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Get Activity Feed ───────────────────────
		wp_register_ability(
			'jetonomy/get-activity',
			array(
				'label'               => __( 'Get Activity Feed', 'jetonomy' ),
				'description'         => __( 'Retrieve the community activity feed showing recent posts, replies, votes, and other events.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Number of entries.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'     => array( 'type' => 'integer' ),
							'action'      => array( 'type' => 'string' ),
							'object_type' => array( 'type' => 'string' ),
							'object_id'   => array( 'type' => 'integer' ),
							'created_at'  => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_activity' ),

				/*
				 * jt_activity_log is an audit trail - it records moderation
				 * actions, bans, trust changes and reputation movement across
				 * every user. wp-admin puts it behind jetonomy_manage_settings
				 * (Admin::add_menu, the Activity Log submenu). This ability had
				 * '__return_true' and its handler applies no filtering of its
				 * own, so the agent surface was the LEAST protected of the
				 * three for the most sensitive read in the plugin.
				 *
				 * Reproduced before the fix: a subscriber for whom
				 * current_user_can('jetonomy_manage_settings') is false received
				 * five rows including other members' reputation changes.
				 * Matched to wp-admin rather than invented - one capability, one
				 * answer, whichever surface asks.
				 */
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_manage_settings' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/* ── Moderation ──────────────────────────────── */

	private function register_moderation_abilities(): void {

		// ── Flag Content ────────────────────────────
		wp_register_ability(
			'jetonomy/flag-content',
			array(
				'label'               => __( 'Flag Content', 'jetonomy' ),
				'description'         => __( 'Report a post, reply or member for moderator review. Provide a reason (spam, offensive, off_topic, harassment, other).', 'jetonomy' ),
				'category'            => 'jetonomy-moderation',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'object_type' => array(
							'type'        => 'string',
							'description' => 'What is reported.',
							'enum'        => array( 'post', 'reply', 'user' ),
							'required'    => true,
						),
						'object_id'   => array(
							'type'        => 'integer',
							'description' => 'Content or member ID.',
							'required'    => true,
						),
						'reason'      => array(
							'type'        => 'string',
							// The REST values, plus the two this ability advertised before (mapped in execute_flag_content).
							'description' => 'Report reason.',
							'enum'        => array( 'spam', 'offensive', 'off_topic', 'harassment', 'other', 'inappropriate', 'off-topic' ),
							'required'    => true,
						),
						'details'     => array(
							'type'        => 'string',
							'description' => 'Additional details, up to 2000 characters.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'flag_id' => array(
							'type'        => 'integer',
							'description' => 'Created flag ID.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_flag_content' ),
				// The capability POST /flags requires; the rest is Moderation_Service::create_flag().
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_flag' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── Moderate Content ────────────────────────
		wp_register_ability(
			'jetonomy/moderate-content',
			array(
				'label'               => __( 'Moderate Content', 'jetonomy' ),
				'description'         => __( 'Take moderation action on flagged content. Approve, trash, or mark as spam. Requires moderator or administrator role.', 'jetonomy' ),
				'category'            => 'jetonomy-moderation',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'object_type' => array(
							'type'        => 'string',
							'description' => 'Content type.',
							'enum'        => array( 'post', 'reply' ),
							'required'    => true,
						),
						'object_id'   => array(
							'type'        => 'integer',
							'description' => 'Content ID.',
							'required'    => true,
						),
						'action'      => array(
							'type'        => 'string',
							'description' => 'Moderation action.',
							'enum'        => array( 'approve', 'trash', 'spam' ),
							'required'    => true,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'new_status' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_moderate_content' ),
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_moderate' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// ── List Flags ──────────────────────────────
		wp_register_ability(
			'jetonomy/list-flags',
			array(
				'label'               => __( 'List Pending Flags', 'jetonomy' ),
				'description'         => __( 'List all pending content flags awaiting moderator review. Requires moderator or administrator role.', 'jetonomy' ),
				'category'            => 'jetonomy-moderation',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Filter by flag status.',
							'enum'        => array( 'pending', 'resolved', 'dismissed' ),
							'default'     => 'pending',
						),
						'limit'  => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array( 'type' => 'object' ),
				),
				'execute_callback'    => array( $this, 'execute_list_flags' ),
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_moderate' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/* ── Search ──────────────────────────────────── */

	private function register_search_abilities(): void {

		wp_register_ability(
			'jetonomy/search',
			array(
				'label'               => __( 'Search Community', 'jetonomy' ),
				'description'         => __( 'Full-text search across posts, spaces, and tags. Supports filtering by type (posts, spaces, tags, all) and returns ranked results.', 'jetonomy' ),
				'category'            => 'jetonomy-search',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'query'  => array(
							'type'        => 'string',
							'description' => 'Search query.',
							'required'    => true,
							'minLength'   => 2,
						),
						'filter' => array(
							'type'        => 'string',
							'description' => 'Result type.',
							'enum'        => array( 'all', 'posts', 'spaces', 'tags' ),
							'default'     => 'all',
						),
						'limit'  => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => true,
					'properties' => array(
						'posts'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'spaces' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'tags'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'  => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_search' ),
				'permission_callback' => array( '\\Jetonomy\\Visibility', 'can_view_community' ), // Private mode: no guest reads.
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/*
	══════════════════════════════════════════════
	 *  Execute Callbacks
	 * ══════════════════════════════════════════════*/

	public function execute_create_post( $input ) {
		$user_id  = get_current_user_id();
		$space_id = (int) $input['space_id'];
		$title    = sanitize_text_field( $input['title'] );
		$content  = wp_kses_post( $input['content'] );

		/*
		 * Leave type empty when the caller did not set one and let
		 * Post::create() derive it from the space.
		 *
		 * The type map lives in \Jetonomy\compose_post_type() so an agent creating a post
		 * writes the same data as a person (including the structured-data type
		 * Schema_Markup keys off). Do not re-add a local branch here.
		 */
		$type = sanitize_text_field( $input['type'] ?? '' );

		$slug       = sanitize_title( $title );
		$is_private = ! empty( $input['is_private'] ) ? 1 : 0;
		$data       = array(
			'space_id'      => $space_id,
			'author_id'     => $user_id,
			'title'         => $title,
			'slug'          => $slug,
			'content'       => $content,
			'content_plain' => \jetonomy_content_to_plain( $content ),
			'type'          => $type,
			'is_private'    => $is_private,
		);

		// Same rate limit as the REST create path.
		$trust = (int) ( UserProfile::find_or_create( $user_id )->trust_level ?? 0 );
		if ( ! \Jetonomy\Permissions\Rate_Limiter::check( $user_id, 'create_posts', $trust ) ) {
			return \Jetonomy\Permissions\Rate_Limiter::error( $user_id, 'create_posts', $trust );
		}

		// Same content rules + require_approval as the REST create path.
		$screened = \Jetonomy\Moderation\Moderation_Service::screen_new_content( 'post', $data, $space_id, $user_id );
		if ( is_wp_error( $screened ) ) {
			return $screened;
		}
		$data['status'] = $screened['status'];

		$post_id = Post::create( $data );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $post_id ) {
			return new WP_Error( 'jetonomy_create_failed', __( 'Failed to create post.', 'jetonomy' ) );
		}
		\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'create_posts' );

		// Tags.
		if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
			foreach ( $input['tags'] as $tag_name ) {
				$tag_id = Tag::find_or_create( sanitize_text_field( $tag_name ) );
				if ( $tag_id ) {
					Tag::attach_to_post( $post_id, $tag_id );
				}
			}
		}

		// Post::create() already counted a published post on the author's profile.
		Subscription::subscribe( $user_id, 'post', $post_id );
		/**
		 * Fires after a post is created through the Abilities API.
		 *
		 * @since 1.0.0
		 *
		 * @param int  $post_id  New post ID.
		 * @param int  $space_id Space ID.
		 * @param null $extra    Always null for Abilities API requests.
		 */
		do_action( 'jetonomy_after_create_post', $post_id, $space_id, null );
		if ( $screened['flag'] ) {
			\Jetonomy\Moderation\Moderation_Service::auto_flag( 'post', (int) $post_id );
		}

		$space = Space::find( $space_id );
		$post  = Post::find( $post_id );

		return array(
			'id'         => $post_id,
			'title'      => $title,
			'is_private' => (bool) $is_private,
			'url'        => \Jetonomy\route_url( 'post', $space->slug ?? '', $post->slug ?? $slug ),
		);
	}

	public function execute_get_post( $input ) {
		$post = Post::find( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ) );
		}
		// Same tombstone the REST post shape applies — this route returns the
		// raw row's title/content, so without it a blocker reading through the
		// abilities surface gets the words the /posts/{id} response withholds.
		Post::apply_block_tombstone(
			$post,
			\Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() )
		);
		$author = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
		return array(
			'id'          => (int) $post->id,
			'title'       => $post->title ?? '',
			'content'     => $post->content ?? '',
			'author_name' => $author['name'],
			'vote_score'  => (int) ( $post->vote_score ?? 0 ),
			'reply_count' => (int) ( $post->reply_count ?? 0 ),
			'status'      => $post->status ?? 'publish',
			'created_at'  => $post->created_at ?? '',
		);
	}

	public function execute_list_posts( $input ) {
		$space_id = (int) $input['space_id'];
		$limit    = (int) ( $input['limit'] ?? 20 );
		$after    = (int) ( $input['after'] ?? 0 );
		$sort     = $input['sort'] ?? 'latest';

		// Filter is_private topics for non-privileged callers.
		// Permission_Engine::is_space_privileged() covers space mods/admins; site
		// admins bypass via the manage_options clause inside list_by_space_visible.
		$user_id       = get_current_user_id();
		$is_privileged = $user_id
			&& ( user_can( $user_id, 'manage_options' )
				|| \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $user_id, $space_id ) );
		$posts         = Post::list_by_space_visible( $space_id, (int) $user_id, (bool) $is_privileged, $sort, $limit, 0, $after );
		$items         = array();
		foreach ( $posts as $p ) {
			$items[] = array(
				'id'          => (int) $p->id,
				'title'       => $p->title ?? '',
				'author_id'   => (int) ( $p->author_id ?? 0 ),
				'vote_score'  => (int) ( $p->vote_score ?? 0 ),
				'reply_count' => (int) ( $p->reply_count ?? 0 ),
				'created_at'  => $p->created_at ?? '',
			);
		}
		$total = Post::count_by_space_visible( $space_id, (int) $user_id, (bool) $is_privileged, $sort );
		return array(
			'posts'    => $items,
			'total'    => $total,
			'has_more' => count( $items ) === $limit && ( count( $items ) + (int) $after ) < $total,
		);
	}

	public function execute_create_reply( $input ) {
		$post_id = (int) $input['post_id'];
		$post    = Post::find( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ), array( 'status' => 404 ) );
		}

		// One reply writer for every non-REST path: gate, rate limit, content rules, after-create hooks.
		// Reply::create() already counts the reply on the author's profile; do not add a second count here.
		$reply_id = \Jetonomy\Permissions\Content_Gate::create_reply( get_current_user_id(), $post, wp_kses_post( $input['content'] ), (int) ( $input['parent_id'] ?? 0 ) );
		if ( is_wp_error( $reply_id ) ) {
			return $reply_id;
		}

		return array(
			'id'      => $reply_id,
			'post_id' => $post_id,
		);
	}

	public function execute_list_replies( $input ) {
		$post_id = (int) $input['post_id'];
		$limit   = (int) ( $input['limit'] ?? 20 );
		$after   = (int) ( $input['after'] ?? 0 );
		$sort    = $input['sort'] ?? 'oldest';

		$replies = Reply::list_by_post( $post_id, $sort, $limit, 0, $after );
		$items   = array();
		foreach ( $replies as $r ) {
			$items[] = array(
				'id'          => (int) $r->id,
				'parent_id'   => $r->parent_id ? (int) $r->parent_id : null,
				'author_id'   => (int) ( $r->author_id ?? 0 ),
				'content'     => $r->content ?? '',
				'vote_score'  => (int) ( $r->vote_score ?? 0 ),
				'is_accepted' => (bool) ( $r->is_accepted ?? false ),
				'created_at'  => $r->created_at ?? '',
			);
		}
		$total = Reply::count_by_post( $post_id );
		return array(
			'replies'  => $items,
			'total'    => $total,
			'has_more' => count( $items ) === $limit && ( count( $items ) + (int) $after ) < $total,
		);
	}

	public function execute_vote( $input ) {
		$user_id     = get_current_user_id();
		$object_type = sanitize_text_field( $input['object_type'] );
		$object_id   = (int) $input['object_id'];
		$value       = (int) $input['value'];

		// Same gate as REST and WP-CLI (live, readable, vote permission, no
		// self-vote); taking back your active vote stays open.
		if ( Vote::get_user_vote( $user_id, $object_type, $object_id ) !== $value ) {
			$gate = \Jetonomy\Permissions\Content_Gate::check_vote( $user_id, $object_type, $object_id, $value );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}

		$result = Vote::record( $user_id, $object_type, $object_id, $value );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Get updated score.
		$score = 0;
		if ( 'post' === $object_type ) {
			$obj   = Post::find( $object_id );
			$score = (int) ( $obj->vote_score ?? 0 );
		} elseif ( 'reply' === $object_type ) {
			$obj   = Reply::find( $object_id );
			$score = (int) ( $obj->vote_score ?? 0 );
		}

		$user_vote = Vote::get_user_vote( $user_id, $object_type, $object_id );

		return array(
			'vote_score' => $score,
			'user_vote'  => (int) $user_vote,
		);
	}

	public function execute_list_spaces( $input ) {
		$category_id = (int) ( $input['category_id'] ?? 0 );
		// Explicit cap: list_all()'s 0-default is unbounded and
		// this payload has no pagination, so an agent on a huge network would
		// materialize every row. The payload shape stays a flat array (a
		// conditional wrapper would break consumers exactly when a big site
		// first hits the cap); 200 covers any realistic community.
		$cap = 200;
		// Both branches must respect the cap. Until 1.9.4 only list_all() got it,
		// so passing a category_id took the unbounded path - defeating the cap
		// in exactly the case the comment above describes, since an agent
		// filtering by category is the normal call, not the exotic one.
		$spaces = $category_id
			? Space::list_by_category( $category_id, null, $cap )
			: Space::list_all( 'active', $cap );
		$items  = array();

		foreach ( $spaces as $s ) {
			// Skip spaces the user can't read.
			if ( ! $this->check_permission_or_public( 'read', (int) $s->id ) ) {
				continue;
			}
			$items[] = array(
				'id'           => (int) $s->id,
				'title'        => $s->title ?? '',
				'slug'         => $s->slug ?? '',
				'type'         => $s->type ?? 'forum',
				'post_count'   => (int) ( $s->post_count ?? 0 ),
				'member_count' => (int) ( $s->member_count ?? 0 ),
			);
		}

		return $items;
	}

	public function execute_get_space( $input ) {
		$space = Space::find( (int) $input['space_id'] );
		if ( ! $space ) {
			/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
			return new WP_Error( 'jetonomy_not_found', sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) );
		}

		// This had NO read gate: it answered with any space's title,
		// description and visibility to any caller holding an id, private and
		// hidden spaces included. list-spaces beside it filtered per row; the
		// single-item twin did not - the same listing-fixed / access-missed
		// split this whole round is about. Same error as the missing row, so an
		// id probe cannot tell "no such space" from "not for you".
		if ( ! $this->check_permission_or_public( 'read', (int) $space->id ) ) {
			/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
			return new WP_Error( 'jetonomy_not_found', sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) );
		}

		return array(
			'id'           => (int) $space->id,
			'title'        => $space->title ?? '',
			'description'  => $space->description ?? '',
			'type'         => $space->type ?? 'forum',
			'visibility'   => $space->visibility ?? 'public',
			'post_count'   => (int) ( $space->post_count ?? 0 ),
			'member_count' => (int) ( $space->member_count ?? 0 ),
		);
	}

	public function execute_join_space( $input ) {
		$user_id  = get_current_user_id();
		$space_id = (int) $input['space_id'];
		$space    = Space::find( $space_id );

		if ( ! $space ) {
			/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
			return new WP_Error( 'jetonomy_not_found', sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) );
		}

		if ( SpaceMember::is_member( $space_id, $user_id ) ) {
			return array( 'status' => 'joined' );
		}

		/*
		 * SpaceMember::join() is the one join authority, so the Abilities path
		 * admits exactly what REST admits: open spaces join, approval spaces
		 * file a request, invite-only and hidden spaces refuse.
		 */
		$result = SpaceMember::join( $space_id, $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'status' => 'pending' === $result['status'] ? 'pending_approval' : 'joined' );
	}

	public function execute_list_space_members( $input ) {
		// Explicit cap — same reasoning as execute_list_spaces:
		// unbounded default + per-row get_userdata/profile below = a 5000-member
		// space materializes and enriches every row for one agent call.
		$members = SpaceMember::list_by_space( (int) $input['space_id'], 200 );
		$items   = array();
		foreach ( $members as $m ) {
			$user    = get_userdata( (int) $m->user_id );
			$profile = UserProfile::find_by_user( (int) $m->user_id );
			$items[] = array(
				'user_id'      => (int) $m->user_id,
				'display_name' => $user ? \Jetonomy\user_display_name( $user ) : __( 'Unknown', 'jetonomy' ),
				'role'         => $m->role ?? 'member',
				'trust_level'  => $profile ? (int) $profile->trust_level : 0,
				'reputation'   => $profile ? (int) $profile->reputation : 0,
			);
		}
		return $items;
	}

	public function execute_create_space( $input ) {
		$title = sanitize_text_field( $input['title'] );
		$slug  = sanitize_title( $title );

		// 'social' was advertised by an earlier schema, but the spaces table
		// ENUM only knows 'feed'. Map it so a legacy caller does not silently
		// store an empty type (which disables the feed UX entirely).
		$type = sanitize_text_field( $input['type'] ?? 'forum' );
		if ( 'social' === $type ) {
			$type = 'feed';
		}

		$data = array(
			'title'       => $title,
			'slug'        => $slug,
			'description' => wp_kses_post( $input['description'] ?? '' ),
			'type'        => $type,
			'visibility'  => sanitize_text_field( $input['visibility'] ?? 'public' ),
		);

		if ( ! empty( $input['category_id'] ) ) {
			$data['category_id'] = (int) $input['category_id'];
		}

		$space_id = Space::create( $data );
		if ( is_wp_error( $space_id ) ) {
			return $space_id;
		}
		if ( ! $space_id ) {
			return new WP_Error( 'jetonomy_create_failed', __( 'Failed to create space.', 'jetonomy' ) );
		}

		// Same as the REST route: the creator follows their new space.
		Subscription::subscribe( get_current_user_id(), 'space', (int) $space_id );

		return array(
			'id'    => $space_id,
			'title' => $title,
			'slug'  => $slug,
		);
	}

	public function execute_get_user_profile( $input ) {
		$user_id = (int) $input['user_id'];
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'jetonomy_not_found', __( 'User not found.', 'jetonomy' ) );
		}
		$profile = UserProfile::find_by_user( $user_id );
		return array(
			'user_id'      => $user_id,
			'display_name' => \Jetonomy\user_display_name( $user ),
			'bio'          => $profile->bio ?? '',
			'trust_level'  => $profile ? (int) $profile->trust_level : 0,
			'reputation'   => $profile ? (int) $profile->reputation : 0,
			'post_count'   => $profile ? (int) $profile->post_count : 0,
			'reply_count'  => $profile ? (int) $profile->reply_count : 0,
			'joined_at'    => $user->user_registered,
		);
	}

	public function execute_list_notifications( $input ) {
		$user_id = get_current_user_id();
		$limit   = (int) ( $input['limit'] ?? 20 );
		$unread  = ! empty( $input['unread_only'] );

		$notifications = Notification::list_for_user( $user_id, $limit );
		$items         = array();
		foreach ( $notifications as $n ) {
			if ( $unread && ! empty( $n->is_read ) ) {
				continue;
			}
			$items[] = array(
				'id'         => (int) $n->id,
				'type'       => $n->type ?? '',
				'message'    => $n->message ?? '',
				'is_read'    => (bool) ( $n->is_read ?? false ),
				'created_at' => $n->created_at ?? '',
			);
		}
		return $items;
	}

	public function execute_mark_notifications_read( $input ) {
		$user_id = get_current_user_id();
		if ( ! empty( $input['notification_id'] ) ) {
			// Only the caller's own notification: an id alone must not mark someone else's read.
			return array( 'marked' => Notification::mark_read_for_user( $user_id, array( (int) $input['notification_id'] ) ) );
		}
		$count = Notification::unread_count( $user_id );
		Notification::mark_all_read( $user_id );
		return array( 'marked' => $count );
	}

	public function execute_get_activity( $input ) {
		$limit   = (int) ( $input['limit'] ?? 20 );
		$rows    = ActivityLog::list_recent( $limit );
		$objects = \Jetonomy\Author::objects_of( $rows );
		$items   = array();
		foreach ( $rows as $r ) {
			// An anonymous author's own rows are reported without who they are, as on the Activity Log screen.
			$hidden  = \Jetonomy\Author::hides_actor( (int) $r->user_id, $objects[ $r->object_type . ':' . (int) $r->object_id ] ?? null );
			$items[] = array(
				'user_id'     => $hidden ? 0 : (int) $r->user_id,
				'action'      => $r->action ?? '',
				'object_type' => $r->object_type ?? '',
				'object_id'   => (int) ( $r->object_id ?? 0 ),
				'created_at'  => $r->created_at ?? '',
			);
		}
		return $items;
	}

	public function execute_flag_content( $input ) {
		$reason  = sanitize_text_field( $input['reason'] );
		$aliases = array(
			'inappropriate' => 'offensive',
			'off-topic'     => 'off_topic',
		);
		$flag_id = \Jetonomy\Moderation\Moderation_Service::create_flag(
			get_current_user_id(),
			sanitize_text_field( $input['object_type'] ),
			(int) $input['object_id'],
			$aliases[ $reason ] ?? $reason,
			sanitize_textarea_field( $input['details'] ?? '' )
		);
		if ( is_wp_error( $flag_id ) ) {
			return $flag_id;
		}
		return array( 'flag_id' => $flag_id );
	}

	public function execute_moderate_content( $input ) {
		$object_type = sanitize_text_field( $input['object_type'] );
		$object_id   = (int) $input['object_id'];
		$action      = sanitize_text_field( $input['action'] );

		// Route through the moderation choke-point so flag resolution, reputation,
		// and the canonical jetonomy_content_moderated action fire identically to
		// the REST and admin paths. The ability's permission_callback already
		// gated on `jetonomy_moderate`, which can_act_on_object() honours.
		$result = \Jetonomy\Moderation\Moderation_Service::set_object_status(
			get_current_user_id(),
			$object_type,
			$object_id,
			$action
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'new_status' => \Jetonomy\Moderation\Moderation_Service::status_for_action( $action ),
		);
	}

	public function execute_list_flags( $input ) {
		$status = sanitize_text_field( $input['status'] ?? 'pending' );
		$limit  = (int) ( $input['limit'] ?? 20 );
		$flags  = Flag::list_by_status( $status, $limit, 0, \Jetonomy\Moderation\Moderation_Permissions::unreadable_space_ids( get_current_user_id() ) );
		$items  = array();
		foreach ( $flags as $f ) {
			$items[] = array(
				'id'          => (int) $f->id,
				'object_type' => $f->object_type ?? '',
				'object_id'   => (int) ( $f->object_id ?? 0 ),
				'reason'      => $f->reason ?? '',
				'user_id'     => (int) ( $f->reporter_id ?? 0 ),
				'created_at'  => $f->created_at ?? '',
			);
		}
		return $items;
	}

	public function execute_search( $input ) {
		$query  = sanitize_text_field( $input['query'] );
		$filter = $input['filter'] ?? 'all';
		$limit  = (int) ( $input['limit'] ?? 20 );

		$adapter = Adapters\Adapter_Registry::get_search();
		$results = array(
			'posts'  => array(),
			'spaces' => array(),
			'tags'   => array(),
			'total'  => 0,
		);

		if ( ! $adapter ) {
			return $results;
		}

		// Route through the public Search_Adapter::search() contract — earlier
		// versions called private methods on the FULLTEXT implementation, which
		// fataled at runtime and was hidden in phpstan-baseline.neon. Each
		// dispatch passes the type the adapter understands; tags stay on the
		// Tag model since they're not part of the search-adapter scope.
		if ( in_array( $filter, array( 'all', 'posts' ), true ) ) {
			$results['posts'] = $adapter->search( $query, 'post', null, $limit, 0 );
		}
		if ( in_array( $filter, array( 'all', 'spaces' ), true ) ) {
			$results['spaces'] = $adapter->search( $query, 'space', null, $limit, 0 );
		}
		if ( in_array( $filter, array( 'all', 'tags' ), true ) ) {
			// Stored post_count includes topics in spaces this viewer cannot open,
			// so show only tags with a readable topic, counted as they see them.
			$found           = Tag::search( $query, $limit );
			$visible         = Post::count_by_tags_visible( array_map( 'intval', array_column( $found, 'id' ) ) );
			$results['tags'] = array();
			foreach ( $found as $tag ) {
				if ( ! empty( $visible[ (int) $tag->id ] ) ) {
					$tag->post_count   = $visible[ (int) $tag->id ];
					$results['tags'][] = $tag;
				}
			}
		}

		$results['total'] = count( $results['posts'] ) + count( $results['spaces'] ) + count( $results['tags'] );

		return $results;
	}

	/*
	══════════════════════════════════════════════
	 *  Permission Helpers
	 * ══════════════════════════════════════════════*/

	private function check_auth_and_permission( string $action, int $space_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		return Permission_Engine::can( $user_id, $action, $space_id );
	}

	/**
	 * Permission for the abilities that read one post or its replies.
	 *
	 * @param array $input Ability input with post_id.
	 * @return bool|WP_Error
	 */
	public function check_read_post( $input ) {
		if ( ! \Jetonomy\Visibility::can_view_community() ) {
			return false;
		}
		$post = Post::find( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ) );
		}
		return Permission_Engine::can_read_post( get_current_user_id(), $post );
	}

	private function check_permission_or_public( string $action, int $space_id ): bool {
		// Private mode closes every read to guests, the same gate REST uses.
		if ( ! \Jetonomy\Visibility::can_view_community() ) {
			return false;
		}
		// One authority, no local restatement: the read rule lives in
		// Permission_Engine::can(), which admits a guest (user 0) to a public space and
		// honours a public space inside a `hidden` category, so list-spaces matches the
		// community listing.
		return Permission_Engine::can( get_current_user_id(), $action, $space_id );
	}
}
