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
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/*
	──────────────────────────────────────────────
	 *  Categories
	 * ──────────────────────────────────────────────*/

	public function register_categories(): void {
		wp_register_ability_category(
			'jetonomy-content',
			[
				'label'       => __( 'Forum Content', 'jetonomy' ),
				'description' => __( 'Create, read, and manage forum posts and replies.', 'jetonomy' ),
			]
		);

		wp_register_ability_category(
			'jetonomy-spaces',
			[
				'label'       => __( 'Community Spaces', 'jetonomy' ),
				'description' => __( 'Manage community spaces (forums, Q&A boards, idea boards).', 'jetonomy' ),
			]
		);

		wp_register_ability_category(
			'jetonomy-users',
			[
				'label'       => __( 'Community Users', 'jetonomy' ),
				'description' => __( 'User profiles, notifications, and subscriptions.', 'jetonomy' ),
			]
		);

		wp_register_ability_category(
			'jetonomy-moderation',
			[
				'label'       => __( 'Content Moderation', 'jetonomy' ),
				'description' => __( 'Flag, review, and moderate community content.', 'jetonomy' ),
			]
		);

		wp_register_ability_category(
			'jetonomy-search',
			[
				'label'       => __( 'Community Search', 'jetonomy' ),
				'description' => __( 'Search posts, spaces, users, and tags across the community.', 'jetonomy' ),
			]
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
			[
				'label'               => __( 'Create Post', 'jetonomy' ),
				'description'         => __( 'Create a new topic, question, or discussion post in a community space. Supports tags and auto-subscribes the author.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'space_id' => [
							'type'        => 'integer',
							'description' => 'Target space ID.',
							'required'    => true,
						],
						'title'    => [
							'type'        => 'string',
							'description' => 'Post title.',
							'required'    => true,
							'minLength'   => 1,
						],
						'content'  => [
							'type'        => 'string',
							'description' => 'Post body (HTML).',
							'required'    => true,
							'minLength'   => 1,
						],
						'type'     => [
							'type'        => 'string',
							'description' => 'Post type.',
							'enum'        => [ 'topic', 'question', 'discussion', 'announcement' ],
						],
						'tags'       => [
							'type'        => 'array',
							'description' => 'Tag names to attach.',
							'items'       => [ 'type' => 'string' ],
						],
						'is_private' => [
							'type'        => 'boolean',
							'description' => 'Mark post as private (only author + moderators can see it).',
							'default'     => false,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'         => [
							'type'        => 'integer',
							'description' => 'Created post ID.',
						],
						'title'      => [ 'type' => 'string' ],
						'is_private' => [
							'type'        => 'boolean',
							'description' => 'Whether the post is private.',
						],
						'url'        => [
							'type'        => 'string',
							'description' => 'Permalink to the post.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_post' ],
				'permission_callback' => function ( $input ) {
					return $this->check_auth_and_permission( 'create_posts', (int) $input['space_id'] );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Post ────────────────────────────────
		wp_register_ability(
			'jetonomy/get-post',
			[
				'label'               => __( 'Get Post', 'jetonomy' ),
				'description'         => __( 'Retrieve a single forum post by ID, including its author, vote score, reply count, and metadata.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'Post ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'          => [ 'type' => 'integer' ],
						'title'       => [ 'type' => 'string' ],
						'content'     => [ 'type' => 'string' ],
						'author_name' => [ 'type' => 'string' ],
						'vote_score'  => [ 'type' => 'integer' ],
						'reply_count' => [ 'type' => 'integer' ],
						'status'      => [ 'type' => 'string' ],
						'created_at'  => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_post' ],
				'permission_callback' => function ( $input ) {
					$post = Post::find( (int) $input['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Post not found.', 'jetonomy' ) );
					}
					return $this->check_permission_or_public( 'read', (int) $post->space_id );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Posts ──────────────────────────────
		wp_register_ability(
			'jetonomy/list-posts',
			[
				'label'               => __( 'List Posts', 'jetonomy' ),
				'description'         => __( 'List posts in a community space with pagination. Returns titles, authors, scores, and reply counts.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'space_id' => [
							'type'        => 'integer',
							'description' => 'Space ID.',
							'required'    => true,
						],
						'limit'    => [
							'type'        => 'integer',
							'description' => 'Results per page (max 50).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						],
						'after'    => [
							'type'        => 'integer',
							'description' => 'Cursor: post ID to start after (for pagination).',
							'default'     => 0,
						],
						'sort'     => [
							'type'        => 'string',
							'description' => 'Sort order.',
							'enum'        => [ 'latest', 'top', 'active' ],
							'default'     => 'latest',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'posts'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'has_more' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_posts' ],
				'permission_callback' => function ( $input ) {
					return $this->check_permission_or_public( 'read', (int) $input['space_id'] );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Reply ────────────────────────────
		wp_register_ability(
			'jetonomy/create-reply',
			[
				'label'               => __( 'Create Reply', 'jetonomy' ),
				'description'         => __( 'Reply to an existing forum post. Supports threaded replies up to 3 levels via parent_id.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'post_id'   => [
							'type'        => 'integer',
							'description' => 'Post to reply to.',
							'required'    => true,
						],
						'content'   => [
							'type'        => 'string',
							'description' => 'Reply body (HTML).',
							'required'    => true,
							'minLength'   => 1,
						],
						'parent_id' => [
							'type'        => 'integer',
							'description' => 'Parent reply ID for threads.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'      => [
							'type'        => 'integer',
							'description' => 'Created reply ID.',
						],
						'post_id' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_reply' ],
				'permission_callback' => function ( $input ) {
					$post = Post::find( (int) $input['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Post not found.', 'jetonomy' ) );
					}
					if ( ! empty( $post->is_closed ) ) {
						return new WP_Error( 'closed', __( 'Post is closed.', 'jetonomy' ) );
					}
					return $this->check_auth_and_permission( 'create_replies', (int) $post->space_id );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Replies ────────────────────────────
		wp_register_ability(
			'jetonomy/list-replies',
			[
				'label'               => __( 'List Replies', 'jetonomy' ),
				'description'         => __( 'List replies for a forum post with pagination. Includes author info, vote scores, and thread structure.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'Post ID.',
							'required'    => true,
						],
						'limit'   => [
							'type'        => 'integer',
							'description' => 'Results per page (max 50).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						],
						'after'   => [
							'type'        => 'integer',
							'description' => 'Cursor: reply ID to start after.',
							'default'     => 0,
						],
						'sort'    => [
							'type'        => 'string',
							'description' => 'Sort order.',
							'enum'        => [ 'oldest', 'newest', 'best' ],
							'default'     => 'oldest',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'replies'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'has_more' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_replies' ],
				'permission_callback' => function ( $input ) {
					$post = Post::find( (int) $input['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'not_found', __( 'Post not found.', 'jetonomy' ) );
					}
					return $this->check_permission_or_public( 'read', (int) $post->space_id );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Vote ────────────────────────────────────
		wp_register_ability(
			'jetonomy/vote',
			[
				'label'               => __( 'Vote on Content', 'jetonomy' ),
				'description'         => __( 'Upvote or downvote a post or reply. Toggles if voting the same direction again.', 'jetonomy' ),
				'category'            => 'jetonomy-content',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'object_type' => [
							'type'        => 'string',
							'description' => 'Content type.',
							'enum'        => [ 'post', 'reply' ],
							'required'    => true,
						],
						'object_id'   => [
							'type'        => 'integer',
							'description' => 'Content ID.',
							'required'    => true,
						],
						'value'       => [
							'type'        => 'integer',
							'description' => '1 for upvote, -1 for downvote.',
							'enum'        => [ 1, -1 ],
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'vote_score' => [
							'type'        => 'integer',
							'description' => 'New total score.',
						],
						'user_vote'  => [
							'type'        => 'integer',
							'description' => 'Current user vote (1, -1, or 0).',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_vote' ],
				'permission_callback' => function ( $input ) {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						return new WP_Error( 'auth', __( 'Authentication required.', 'jetonomy' ) );
					}
					return Permission_Engine::can( $user_id, 'vote' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Spaces ──────────────────────────────────── */

	private function register_space_abilities(): void {

		// ── List Spaces ─────────────────────────────
		wp_register_ability(
			'jetonomy/list-spaces',
			[
				'label'               => __( 'List Spaces', 'jetonomy' ),
				'description'         => __( 'List all community spaces (forums, Q&A boards, idea boards) the current user can access, grouped by category.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'category_id' => [
							'type'        => 'integer',
							'description' => 'Filter by category ID.',
						],
					],
				],
				'output_schema'       => [
					'type'     => 'array',
					'required' => true,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'id'           => [ 'type' => 'integer' ],
							'title'        => [ 'type' => 'string' ],
							'slug'         => [ 'type' => 'string' ],
							'type'         => [ 'type' => 'string' ],
							'post_count'   => [ 'type' => 'integer' ],
							'member_count' => [ 'type' => 'integer' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_spaces' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Space ───────────────────────────────
		wp_register_ability(
			'jetonomy/get-space',
			[
				'label'               => __( 'Get Space Details', 'jetonomy' ),
				'description'         => __( 'Retrieve detailed information about a community space including description, rules, member count, and settings.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'space_id' => [
							'type'        => 'integer',
							'description' => 'Space ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'           => [ 'type' => 'integer' ],
						'title'        => [ 'type' => 'string' ],
						'description'  => [ 'type' => 'string' ],
						'type'         => [ 'type' => 'string' ],
						'visibility'   => [ 'type' => 'string' ],
						'post_count'   => [ 'type' => 'integer' ],
						'member_count' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_space' ],
				'permission_callback' => function ( $input ) {
					return $this->check_permission_or_public( 'read', (int) $input['space_id'] );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Join Space ──────────────────────────────
		wp_register_ability(
			'jetonomy/join-space',
			[
				'label'               => __( 'Join Space', 'jetonomy' ),
				'description'         => __( 'Join a community space as a member. For private spaces, this submits a join request for moderator approval.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'space_id' => [
							'type'        => 'integer',
							'description' => 'Space to join.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'status' => [
							'type'        => 'string',
							'description' => 'joined or pending_approval.',
							'enum'        => [ 'joined', 'pending_approval' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_join_space' ],
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Space Members ──────────────────────
		wp_register_ability(
			'jetonomy/list-space-members',
			[
				'label'               => __( 'List Space Members', 'jetonomy' ),
				'description'         => __( 'List members of a community space with their roles, trust levels, and reputation.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'space_id' => [
							'type'        => 'integer',
							'description' => 'Space ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'     => 'array',
					'required' => true,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'user_id'      => [ 'type' => 'integer' ],
							'display_name' => [ 'type' => 'string' ],
							'role'         => [ 'type' => 'string' ],
							'trust_level'  => [ 'type' => 'integer' ],
							'reputation'   => [ 'type' => 'integer' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_space_members' ],
				'permission_callback' => function ( $input ) {
					return $this->check_permission_or_public( 'read', (int) $input['space_id'] );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Create Space (Admin) ────────────────────
		wp_register_ability(
			'jetonomy/create-space',
			[
				'label'               => __( 'Create Space', 'jetonomy' ),
				'description'         => __( 'Create a new community space (forum, Q&A, ideas, or social). Requires administrator or jetonomy_manage_settings capability.', 'jetonomy' ),
				'category'            => 'jetonomy-spaces',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'title'       => [
							'type'        => 'string',
							'description' => 'Space name.',
							'required'    => true,
							'minLength'   => 1,
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Space description.',
						],
						'type'        => [
							'type'        => 'string',
							'description' => 'Space type.',
							'enum'        => [ 'forum', 'qa', 'ideas', 'social' ],
							'default'     => 'forum',
						],
						'visibility'  => [
							'type'        => 'string',
							'description' => 'Visibility level.',
							'enum'        => [ 'public', 'private', 'hidden' ],
							'default'     => 'public',
						],
						'category_id' => [
							'type'        => 'integer',
							'description' => 'Parent category ID.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'id'    => [
							'type'        => 'integer',
							'description' => 'Created space ID.',
						],
						'title' => [ 'type' => 'string' ],
						'slug'  => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_space' ],
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_manage_settings' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Users ───────────────────────────────────── */

	private function register_user_abilities(): void {

		// ── Get User Profile ────────────────────────
		wp_register_ability(
			'jetonomy/get-user-profile',
			[
				'label'               => __( 'Get User Profile', 'jetonomy' ),
				'description'         => __( 'Retrieve a community member\'s profile including bio, trust level, reputation, post/reply counts, and badges.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id' => [
							'type'        => 'integer',
							'description' => 'WordPress user ID.',
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'user_id'      => [ 'type' => 'integer' ],
						'display_name' => [ 'type' => 'string' ],
						'bio'          => [ 'type' => 'string' ],
						'trust_level'  => [ 'type' => 'integer' ],
						'reputation'   => [ 'type' => 'integer' ],
						'post_count'   => [ 'type' => 'integer' ],
						'reply_count'  => [ 'type' => 'integer' ],
						'joined_at'    => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_user_profile' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Notifications ──────────────────────
		wp_register_ability(
			'jetonomy/list-notifications',
			[
				'label'               => __( 'List Notifications', 'jetonomy' ),
				'description'         => __( 'List the current user\'s community notifications (replies, mentions, votes, badges) with read/unread status.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'unread_only' => [
							'type'        => 'boolean',
							'description' => 'Only return unread notifications.',
							'default'     => false,
						],
						'limit'       => [
							'type'        => 'integer',
							'description' => 'Results per page.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						],
					],
				],
				'output_schema'       => [
					'type'     => 'array',
					'required' => true,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'id'         => [ 'type' => 'integer' ],
							'type'       => [ 'type' => 'string' ],
							'message'    => [ 'type' => 'string' ],
							'is_read'    => [ 'type' => 'boolean' ],
							'created_at' => [ 'type' => 'string' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_notifications' ],
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Mark Notifications Read ─────────────────
		wp_register_ability(
			'jetonomy/mark-notifications-read',
			[
				'label'               => __( 'Mark Notifications Read', 'jetonomy' ),
				'description'         => __( 'Mark one or all of the current user\'s notifications as read.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'notification_id' => [
							'type'        => 'integer',
							'description' => 'Specific notification ID. Omit to mark all as read.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'marked' => [
							'type'        => 'integer',
							'description' => 'Number of notifications marked read.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_mark_notifications_read' ],
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Get Activity Feed ───────────────────────
		wp_register_ability(
			'jetonomy/get-activity',
			[
				'label'               => __( 'Get Activity Feed', 'jetonomy' ),
				'description'         => __( 'Retrieve the community activity feed showing recent posts, replies, votes, and other events.', 'jetonomy' ),
				'category'            => 'jetonomy-users',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'limit' => [
							'type'        => 'integer',
							'description' => 'Number of entries.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						],
					],
				],
				'output_schema'       => [
					'type'     => 'array',
					'required' => true,
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'user_id'     => [ 'type' => 'integer' ],
							'action'      => [ 'type' => 'string' ],
							'object_type' => [ 'type' => 'string' ],
							'object_id'   => [ 'type' => 'integer' ],
							'created_at'  => [ 'type' => 'string' ],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_activity' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Moderation ──────────────────────────────── */

	private function register_moderation_abilities(): void {

		// ── Flag Content ────────────────────────────
		wp_register_ability(
			'jetonomy/flag-content',
			[
				'label'               => __( 'Flag Content', 'jetonomy' ),
				'description'         => __( 'Flag a post or reply for moderator review. Provide a reason (spam, inappropriate, off-topic, other).', 'jetonomy' ),
				'category'            => 'jetonomy-moderation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'object_type' => [
							'type'        => 'string',
							'description' => 'Content type.',
							'enum'        => [ 'post', 'reply' ],
							'required'    => true,
						],
						'object_id'   => [
							'type'        => 'integer',
							'description' => 'Content ID.',
							'required'    => true,
						],
						'reason'      => [
							'type'        => 'string',
							'description' => 'Flag reason.',
							'enum'        => [ 'spam', 'inappropriate', 'off-topic', 'other' ],
							'required'    => true,
						],
						'details'     => [
							'type'        => 'string',
							'description' => 'Additional details.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'flag_id' => [
							'type'        => 'integer',
							'description' => 'Created flag ID.',
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_flag_content' ],
				'permission_callback' => function () {
					return (bool) get_current_user_id();
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── Moderate Content ────────────────────────
		wp_register_ability(
			'jetonomy/moderate-content',
			[
				'label'               => __( 'Moderate Content', 'jetonomy' ),
				'description'         => __( 'Take moderation action on flagged content. Approve, trash, or mark as spam. Requires moderator or administrator role.', 'jetonomy' ),
				'category'            => 'jetonomy-moderation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'object_type' => [
							'type'        => 'string',
							'description' => 'Content type.',
							'enum'        => [ 'post', 'reply' ],
							'required'    => true,
						],
						'object_id'   => [
							'type'        => 'integer',
							'description' => 'Content ID.',
							'required'    => true,
						],
						'action'      => [
							'type'        => 'string',
							'description' => 'Moderation action.',
							'enum'        => [ 'approve', 'trash', 'spam' ],
							'required'    => true,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'success'    => [ 'type' => 'boolean' ],
						'new_status' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_moderate_content' ],
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_moderate' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		// ── List Flags ──────────────────────────────
		wp_register_ability(
			'jetonomy/list-flags',
			[
				'label'               => __( 'List Pending Flags', 'jetonomy' ),
				'description'         => __( 'List all pending content flags awaiting moderator review. Requires moderator or administrator role.', 'jetonomy' ),
				'category'            => 'jetonomy-moderation',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'status' => [
							'type'        => 'string',
							'description' => 'Filter by flag status.',
							'enum'        => [ 'pending', 'resolved', 'dismissed' ],
							'default'     => 'pending',
						],
						'limit'  => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						],
					],
				],
				'output_schema'       => [
					'type'     => 'array',
					'required' => true,
					'items'    => [ 'type' => 'object' ],
				],
				'execute_callback'    => [ $this, 'execute_list_flags' ],
				'permission_callback' => function () {
					return current_user_can( 'jetonomy_moderate' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/* ── Search ──────────────────────────────────── */

	private function register_search_abilities(): void {

		wp_register_ability(
			'jetonomy/search',
			[
				'label'               => __( 'Search Community', 'jetonomy' ),
				'description'         => __( 'Full-text search across posts, spaces, and tags. Supports filtering by type (posts, spaces, tags, all) and returns ranked results.', 'jetonomy' ),
				'category'            => 'jetonomy-search',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'query'  => [
							'type'        => 'string',
							'description' => 'Search query.',
							'required'    => true,
							'minLength'   => 2,
						],
						'filter' => [
							'type'        => 'string',
							'description' => 'Result type.',
							'enum'        => [ 'all', 'posts', 'spaces', 'tags' ],
							'default'     => 'all',
						],
						'limit'  => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'required'   => true,
					'properties' => [
						'posts'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'spaces' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'tags'   => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'total'  => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_search' ],
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
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
		$type     = sanitize_text_field( $input['type'] ?? '' );

		if ( empty( $type ) ) {
			$space = Space::find( $space_id );
			$type  = ( $space && 'qa' === ( $space->type ?? '' ) ) ? 'question' : 'topic';
		}

		$slug       = sanitize_title( $title );
		$is_private = ! empty( $input['is_private'] ) ? 1 : 0;
		$post_id    = Post::create(
			[
				'space_id'      => $space_id,
				'author_id'     => $user_id,
				'title'         => $title,
				'slug'          => $slug,
				'content'       => $content,
				'content_plain' => wp_strip_all_tags( $content ),
				'type'          => $type,
				'is_private'    => $is_private,
			]
		);

		if ( ! $post_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create post.', 'jetonomy' ) );
		}

		// Tags.
		if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
			foreach ( $input['tags'] as $tag_name ) {
				$tag_id = Tag::find_or_create( sanitize_text_field( $tag_name ) );
				if ( $tag_id ) {
					Tag::attach_to_post( $post_id, $tag_id );
				}
			}
		}

		UserProfile::increment_post_count( $user_id );
		Subscription::subscribe( $user_id, 'post', $post_id );
		do_action( 'jetonomy_after_create_post', $post_id, $space_id, null );

		$settings  = get_option( 'jetonomy_settings', [] );
		$base_slug = $settings['base_slug'] ?? 'community';
		$space     = Space::find( $space_id );
		$post      = Post::find( $post_id );

		return [
			'id'         => $post_id,
			'title'      => $title,
			'is_private' => (bool) $is_private,
			'url'        => home_url( "/{$base_slug}/s/" . ( $space->slug ?? '' ) . '/t/' . ( $post->slug ?? $slug ) . '/' ),
		];
	}

	public function execute_get_post( $input ) {
		$post = Post::find( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'jetonomy' ) );
		}
		$author = get_userdata( (int) $post->author_id );
		return [
			'id'          => (int) $post->id,
			'title'       => $post->title ?? '',
			'content'     => $post->content ?? '',
			'author_name' => $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ),
			'vote_score'  => (int) ( $post->vote_score ?? 0 ),
			'reply_count' => (int) ( $post->reply_count ?? 0 ),
			'status'      => $post->status ?? 'publish',
			'created_at'  => $post->created_at ?? '',
		];
	}

	public function execute_list_posts( $input ) {
		$space_id = (int) $input['space_id'];
		$limit    = (int) ( $input['limit'] ?? 20 );
		$after    = (int) ( $input['after'] ?? 0 );
		$sort     = $input['sort'] ?? 'latest';

		$posts = Post::list_by_space( $space_id, $sort, $limit, 0, $after );
		$items = [];
		foreach ( $posts as $p ) {
			$items[] = [
				'id'          => (int) $p->id,
				'title'       => $p->title ?? '',
				'author_id'   => (int) ( $p->author_id ?? 0 ),
				'vote_score'  => (int) ( $p->vote_score ?? 0 ),
				'reply_count' => (int) ( $p->reply_count ?? 0 ),
				'created_at'  => $p->created_at ?? '',
			];
		}
		return [
			'posts'    => $items,
			'has_more' => count( $items ) === $limit,
		];
	}

	public function execute_create_reply( $input ) {
		$user_id = get_current_user_id();
		$post_id = (int) $input['post_id'];
		$content = wp_kses_post( $input['content'] );

		$data = [
			'post_id'       => $post_id,
			'author_id'     => $user_id,
			'content'       => $content,
			'content_plain' => wp_strip_all_tags( $content ),
		];

		if ( ! empty( $input['parent_id'] ) ) {
			$data['parent_id'] = (int) $input['parent_id'];
		}

		$reply_id = Reply::create( $data );
		if ( ! $reply_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create reply.', 'jetonomy' ) );
		}

		UserProfile::increment_reply_count( $user_id );
		do_action( 'jetonomy_after_create_reply', $reply_id, $post_id );

		return [
			'id'      => $reply_id,
			'post_id' => $post_id,
		];
	}

	public function execute_list_replies( $input ) {
		$post_id = (int) $input['post_id'];
		$limit   = (int) ( $input['limit'] ?? 20 );
		$after   = (int) ( $input['after'] ?? 0 );
		$sort    = $input['sort'] ?? 'oldest';

		$replies = Reply::list_by_post( $post_id, $sort, $limit, 0, $after );
		$items   = [];
		foreach ( $replies as $r ) {
			$items[] = [
				'id'          => (int) $r->id,
				'parent_id'   => $r->parent_id ? (int) $r->parent_id : null,
				'author_id'   => (int) ( $r->author_id ?? 0 ),
				'content'     => $r->content ?? '',
				'vote_score'  => (int) ( $r->vote_score ?? 0 ),
				'is_accepted' => (bool) ( $r->is_accepted ?? false ),
				'created_at'  => $r->created_at ?? '',
			];
		}
		return [
			'replies'  => $items,
			'has_more' => count( $items ) === $limit,
		];
	}

	public function execute_vote( $input ) {
		$user_id     = get_current_user_id();
		$object_type = sanitize_text_field( $input['object_type'] );
		$object_id   = (int) $input['object_id'];
		$value       = (int) $input['value'];

		$result = Vote::cast( $user_id, $object_type, $object_id, $value );

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

		do_action( 'jetonomy_after_vote', $object_type, $object_id, $user_id );

		return [
			'vote_score' => $score,
			'user_vote'  => (int) $user_vote,
		];
	}

	public function execute_list_spaces( $input ) {
		$category_id = (int) ( $input['category_id'] ?? 0 );
		$spaces      = $category_id ? Space::list_by_category( $category_id ) : Space::list_all();
		$items       = [];

		foreach ( $spaces as $s ) {
			// Skip spaces the user can't read.
			if ( ! $this->check_permission_or_public( 'read', (int) $s->id ) ) {
				continue;
			}
			$items[] = [
				'id'           => (int) $s->id,
				'title'        => $s->title ?? '',
				'slug'         => $s->slug ?? '',
				'type'         => $s->type ?? 'forum',
				'post_count'   => (int) ( $s->post_count ?? 0 ),
				'member_count' => (int) ( $s->member_count ?? 0 ),
			];
		}

		return $items;
	}

	public function execute_get_space( $input ) {
		$space = Space::find( (int) $input['space_id'] );
		if ( ! $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'jetonomy' ) );
		}
		return [
			'id'           => (int) $space->id,
			'title'        => $space->title ?? '',
			'description'  => $space->description ?? '',
			'type'         => $space->type ?? 'forum',
			'visibility'   => $space->visibility ?? 'public',
			'post_count'   => (int) ( $space->post_count ?? 0 ),
			'member_count' => (int) ( $space->member_count ?? 0 ),
		];
	}

	public function execute_join_space( $input ) {
		$user_id  = get_current_user_id();
		$space_id = (int) $input['space_id'];
		$space    = Space::find( $space_id );

		if ( ! $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'jetonomy' ) );
		}

		if ( SpaceMember::is_member( $space_id, $user_id ) ) {
			return [ 'status' => 'joined' ];
		}

		if ( 'private' === ( $space->visibility ?? 'public' ) ) {
			Models\JoinRequest::create( $space_id, $user_id );
			return [ 'status' => 'pending_approval' ];
		}

		SpaceMember::add( $space_id, $user_id, 'member' );
		return [ 'status' => 'joined' ];
	}

	public function execute_list_space_members( $input ) {
		$members = SpaceMember::list_by_space( (int) $input['space_id'] );
		$items   = [];
		foreach ( $members as $m ) {
			$user    = get_userdata( (int) $m->user_id );
			$profile = UserProfile::find_by_user( (int) $m->user_id );
			$items[] = [
				'user_id'      => (int) $m->user_id,
				'display_name' => $user ? $user->display_name : __( 'Unknown', 'jetonomy' ),
				'role'         => $m->role ?? 'member',
				'trust_level'  => $profile ? (int) $profile->trust_level : 0,
				'reputation'   => $profile ? (int) $profile->reputation : 0,
			];
		}
		return $items;
	}

	public function execute_create_space( $input ) {
		$title = sanitize_text_field( $input['title'] );
		$slug  = sanitize_title( $title );

		$data = [
			'title'       => $title,
			'slug'        => $slug,
			'description' => wp_kses_post( $input['description'] ?? '' ),
			'type'        => sanitize_text_field( $input['type'] ?? 'forum' ),
			'visibility'  => sanitize_text_field( $input['visibility'] ?? 'public' ),
		];

		if ( ! empty( $input['category_id'] ) ) {
			$data['category_id'] = (int) $input['category_id'];
		}

		$space_id = Space::create( $data );
		if ( ! $space_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create space.', 'jetonomy' ) );
		}

		SpaceMember::add( $space_id, get_current_user_id(), 'admin' );

		return [
			'id'    => $space_id,
			'title' => $title,
			'slug'  => $slug,
		];
	}

	public function execute_get_user_profile( $input ) {
		$user_id = (int) $input['user_id'];
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'jetonomy' ) );
		}
		$profile = UserProfile::find_by_user( $user_id );
		return [
			'user_id'      => $user_id,
			'display_name' => $user->display_name,
			'bio'          => $profile->bio ?? '',
			'trust_level'  => $profile ? (int) $profile->trust_level : 0,
			'reputation'   => $profile ? (int) $profile->reputation : 0,
			'post_count'   => $profile ? (int) $profile->post_count : 0,
			'reply_count'  => $profile ? (int) $profile->reply_count : 0,
			'joined_at'    => $user->user_registered,
		];
	}

	public function execute_list_notifications( $input ) {
		$user_id = get_current_user_id();
		$limit   = (int) ( $input['limit'] ?? 20 );
		$unread  = ! empty( $input['unread_only'] );

		$notifications = Notification::list_for_user( $user_id, $limit );
		$items         = [];
		foreach ( $notifications as $n ) {
			if ( $unread && ! empty( $n->is_read ) ) {
				continue;
			}
			$items[] = [
				'id'         => (int) $n->id,
				'type'       => $n->type ?? '',
				'message'    => $n->message ?? '',
				'is_read'    => (bool) ( $n->is_read ?? false ),
				'created_at' => $n->created_at ?? '',
			];
		}
		return $items;
	}

	public function execute_mark_notifications_read( $input ) {
		$user_id = get_current_user_id();
		if ( ! empty( $input['notification_id'] ) ) {
			Notification::mark_read( (int) $input['notification_id'] );
			return [ 'marked' => 1 ];
		}
		$count = Notification::mark_all_read( $user_id );
		return [ 'marked' => (int) $count ];
	}

	public function execute_get_activity( $input ) {
		$limit = (int) ( $input['limit'] ?? 20 );
		$rows  = ActivityLog::list_recent( $limit );
		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'user_id'     => (int) $r->user_id,
				'action'      => $r->action ?? '',
				'object_type' => $r->object_type ?? '',
				'object_id'   => (int) ( $r->object_id ?? 0 ),
				'created_at'  => $r->created_at ?? '',
			];
		}
		return $items;
	}

	public function execute_flag_content( $input ) {
		$flag_id = Flag::create(
			[
				'object_type' => sanitize_text_field( $input['object_type'] ),
				'object_id'   => (int) $input['object_id'],
				'user_id'     => get_current_user_id(),
				'reason'      => sanitize_text_field( $input['reason'] ),
				'details'     => sanitize_textarea_field( $input['details'] ?? '' ),
			]
		);
		if ( ! $flag_id ) {
			return new WP_Error( 'flag_failed', __( 'Failed to create flag.', 'jetonomy' ) );
		}
		return [ 'flag_id' => $flag_id ];
	}

	public function execute_moderate_content( $input ) {
		$object_type = sanitize_text_field( $input['object_type'] );
		$object_id   = (int) $input['object_id'];
		$action      = sanitize_text_field( $input['action'] );

		$status_map = [
			'approve' => 'publish',
			'trash'   => 'trash',
			'spam'    => 'spam',
		];
		$new_status = $status_map[ $action ] ?? 'publish';

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => $new_status ] );
		} elseif ( 'reply' === $object_type ) {
			Reply::update( $object_id, [ 'status' => $new_status ] );
		}

		do_action( 'jetonomy_content_moderated', $action, $object_type, $object_id, get_current_user_id() );

		return [
			'success'    => true,
			'new_status' => $new_status,
		];
	}

	public function execute_list_flags( $input ) {
		$status = sanitize_text_field( $input['status'] ?? 'pending' );
		$limit  = (int) ( $input['limit'] ?? 20 );
		$flags  = Flag::list_by_status( $status, $limit );
		$items  = [];
		foreach ( $flags as $f ) {
			$items[] = [
				'id'          => (int) $f->id,
				'object_type' => $f->object_type ?? '',
				'object_id'   => (int) ( $f->object_id ?? 0 ),
				'reason'      => $f->reason ?? '',
				'user_id'     => (int) ( $f->user_id ?? 0 ),
				'created_at'  => $f->created_at ?? '',
			];
		}
		return $items;
	}

	public function execute_search( $input ) {
		$query  = sanitize_text_field( $input['query'] );
		$filter = $input['filter'] ?? 'all';
		$limit  = (int) ( $input['limit'] ?? 20 );

		$adapter = Adapters\Adapter_Registry::get_search();
		$results = [
			'posts'  => [],
			'spaces' => [],
			'tags'   => [],
			'total'  => 0,
		];

		if ( in_array( $filter, [ 'all', 'posts' ], true ) ) {
			$results['posts'] = $adapter->search_posts( $query, $limit );
		}
		if ( in_array( $filter, [ 'all', 'spaces' ], true ) ) {
			$results['spaces'] = $adapter->search_spaces( $query, $limit );
		}
		if ( in_array( $filter, [ 'all', 'tags' ], true ) ) {
			$results['tags'] = Tag::search( $query, $limit );
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

	private function check_permission_or_public( string $action, int $space_id ): bool {
		$user_id = get_current_user_id();
		// Guests can read public spaces.
		if ( 'read' === $action ) {
			$space = Space::find( $space_id );
			if ( $space && 'public' === ( $space->visibility ?? 'public' ) ) {
				return true;
			}
		}
		if ( ! $user_id ) {
			return false;
		}
		return Permission_Engine::can( $user_id, $action, $space_id );
	}
}
