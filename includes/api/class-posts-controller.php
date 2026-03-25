<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\Revision;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Tag;
use Jetonomy\Models\UserProfile;

class Posts_Controller extends Base_Controller {

	protected $rest_base = 'posts';

	/**
	 * Register all REST routes for posts.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Collection routes nested under spaces.
		register_rest_route( $ns, '/spaces/(?P<space_id>\d+)/posts', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->get_collection_params(),
					[
						'space_id' => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
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
		register_rest_route( $ns, '/posts/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
			],
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

		// Action routes.
		register_rest_route( $ns, '/posts/(?P<id>\d+)/close', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'close_post' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/posts/(?P<id>\d+)/pin', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'pin_post' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/posts/(?P<id>\d+)/move', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'move_post' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'target_space_id' => [
					'type'     => 'integer',
					'required' => true,
					'minimum'  => 1,
				],
			],
		] );
	}

	/**
	 * GET /spaces/{space_id}/posts — List posts in a space.
	 */
	public function list_items( $request ) {
		$space_id = absint( $request->get_param( 'space_id' ) );

		if ( ! $this->check_permission( 'read', $space_id ) ) {
			return $this->permission_error();
		}

		$space = Space::find( $space_id );
		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$pagination = $this->get_pagination( $request );
		$posts      = Post::list_by_space(
			$space_id,
			$pagination['sort'],
			(int) $pagination['limit'],
			(int) $pagination['offset'],
			(int) $pagination['after']
		);

		// Eager-load all author data in a single batch before preparing items.
		$posts = $this->enrich_with_author( $posts );

		$items = array_map( [ $this, 'prepare_post' ], $posts );

		return $this->paginated_response( $items, [
			'total'    => (int) ( $space->post_count ?? 0 ),
			'has_more' => count( $items ) === (int) $pagination['limit'],
		] );
	}

	/**
	 * GET /posts/{id} — Retrieve a single post.
	 */
	public function get_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		if ( ! $this->check_permission( 'read', (int) $post->space_id ) ) {
			return $this->permission_error();
		}

		Post::increment_view_count( $id );

		return new WP_REST_Response( $this->prepare_post( $post ), 200 );
	}

	/**
	 * POST /spaces/{space_id}/posts — Create a new post.
	 */
	public function create_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$space_id = absint( $request->get_param( 'space_id' ) );

		if ( ! $this->check_permission( 'create_posts', $space_id ) ) {
			return $this->permission_error();
		}

		$space = Space::find( $space_id );
		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		// Block posting to archived or locked spaces.
		if ( in_array( $space->status ?? '', [ 'archived', 'locked' ], true ) ) {
			return new WP_Error(
				'jetonomy_space_restricted',
				__( 'This space is archived or locked and no longer accepts new posts.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		// Rate limit check.
		$profile = UserProfile::find_or_create( $user_id );
		$trust   = (int) ( $profile->trust_level ?? 0 );
		if ( ! \Jetonomy\Permissions\Rate_Limiter::check( $user_id, 'create_posts', $trust ) ) {
			return $this->validation_error( __( 'Rate limit exceeded. Please try again later.', 'jetonomy' ) );
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( empty( $title ) ) {
			return $this->validation_error( __( 'Post title is required.', 'jetonomy' ) );
		}

		$content = wp_kses_post( (string) $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return $this->validation_error( __( 'Post content is required.', 'jetonomy' ) );
		}

		// Derive post type from space type if not provided.
		$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
		if ( empty( $type ) ) {
			$space_type_to_post_type = [
				'qa'    => 'question',
				'ideas' => 'idea',
				'feed'  => 'status',
			];
			$space_type = $space->type ?? 'forum';
			$type       = $space_type_to_post_type[ $space_type ] ?? 'topic';
		}

		$content_plain = wp_strip_all_tags( $content );
		$slug          = $this->unique_post_slug( sanitize_title( $title ) );

		// Akismet spam check.
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$user = get_userdata( $user_id );
		$akismet_spam = \Jetonomy\Moderation\Akismet::check_spam(
			$content,
			$user->display_name ?? '',
			$user->user_email ?? '',
			$ip
		);

		$post_data = [
			'space_id'      => $space_id,
			'author_id'     => $user_id,
			'title'         => $title,
			'slug'          => $slug,
			'content'       => $content,
			'content_plain' => $content_plain,
			'type'          => $type,
		];

		if ( $akismet_spam ) {
			$post_data['status'] = 'spam';
		}

		/**
		 * Check content against moderation rules before insertion.
		 *
		 * @param string|null $action   null if no action, or 'flag', 'hold', 'block', 'spam'.
		 * @param array       $data     Post data array with 'title' and 'content' keys.
		 * @param int         $space_id Space ID.
		 * @param int         $user_id  Author user ID.
		 */
		$moderation_action = apply_filters( 'jetonomy_check_content', null, $post_data, $space_id, $user_id );

		if ( 'block' === $moderation_action ) {
			return $this->validation_error( __( 'Your post was blocked by our content policy.', 'jetonomy' ) );
		}
		if ( 'hold' === $moderation_action ) {
			$post_data['status'] = 'pending';
		}
		if ( 'spam' === $moderation_action ) {
			$post_data['status'] = 'spam';
		}

		$post_id = Post::create( $post_data );

		if ( ! $post_id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create post.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		// Fire action for Activity_Tracker, Notifier, and other listeners.
		do_action( 'jetonomy_after_create_post', $post_id, $space_id );

		// Update user profile post count.
		UserProfile::increment_post_count( $user_id );

		// Increment rate limit counter.
		\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'create_posts' );

		// Auto-subscribe the author.
		Subscription::subscribe( $user_id, 'post', $post_id );

		// Attach tags if provided.
		$tags = $request->get_param( 'tags' );
		if ( ! empty( $tags ) && is_array( $tags ) ) {
			foreach ( $tags as $tag_name ) {
				$tag_name = sanitize_text_field( (string) $tag_name );
				if ( ! empty( $tag_name ) ) {
					$tag_id = Tag::find_or_create( $tag_name );
					if ( $tag_id ) {
						Tag::attach_to_post( $post_id, $tag_id );
					}
				}
			}
		}

		$post = Post::find( $post_id );

		// Parse @mentions and notify.
		$mentioned = \Jetonomy\Mentions::extract_user_ids( $content );
		if ( ! empty( $mentioned ) ) {
			\Jetonomy\Mentions::notify( $mentioned, $user_id, 'post', $post_id, $title );
		}

		return new WP_REST_Response( $this->prepare_post( $post ), 201 );
	}

	/**
	 * PATCH /posts/{id} — Update a post.
	 */
	public function update_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space_id  = (int) $post->space_id;
		$is_author = (int) $post->author_id === $user_id;

		// Must be author with edit_own_posts or moderator/admin with edit_others_posts.
		$can_edit = ( $is_author && $this->check_permission( 'create_posts', $space_id ) )
			|| $this->check_permission( 'edit_others_posts', $space_id );

		if ( ! $can_edit ) {
			return $this->permission_error();
		}

		$update_data = [];

		if ( null !== $request->get_param( 'title' ) ) {
			$update_data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}

		if ( null !== $request->get_param( 'content' ) ) {
			$content                    = wp_kses_post( $request->get_param( 'content' ) );
			$update_data['content']     = $content;
			$update_data['content_plain'] = wp_strip_all_tags( $content );
		}

		if ( empty( $update_data ) ) {
			return $this->validation_error( __( 'No fields provided for update.', 'jetonomy' ) );
		}

		// Advanced Moderation: check updated content.
		$check_data        = array_intersect_key( $update_data, array_flip( [ 'title', 'content' ] ) );
		$moderation_action = apply_filters( 'jetonomy_check_content', null, $check_data, $space_id, $user_id );
		if ( 'block' === $moderation_action ) {
			return $this->validation_error( __( 'Your post was blocked by our content policy.', 'jetonomy' ) );
		}

		// Create a revision before updating.
		Revision::create( [
			'object_type' => 'post',
			'object_id'   => $id,
			'author_id'   => $user_id,
			'content'     => $post->content ?? '',
			'title'       => $post->title ?? '',
		] );

		$update_data['edited_at'] = current_time( 'mysql' );
		$update_data['edited_by'] = $user_id;

		Post::update( $id, $update_data );

		do_action( 'jetonomy_post_updated', $id, $space_id, $user_id );

		$updated = Post::find( $id );

		return new WP_REST_Response( $this->prepare_post( $updated ), 200 );
	}

	/**
	 * DELETE /posts/{id} — Soft-delete (trash) a post.
	 */
	public function delete_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space_id  = (int) $post->space_id;
		$is_author = (int) $post->author_id === $user_id;

		$can_delete = ( $is_author && $this->check_permission( 'create_posts', $space_id ) )
			|| $this->check_permission( 'delete_others_posts', $space_id );

		if ( ! $can_delete ) {
			return $this->permission_error();
		}

		Post::update( $id, [ 'status' => 'trash' ] );

		do_action( 'jetonomy_post_deleted', $id, $space_id, $user_id );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * POST /posts/{id}/close — Close a post to prevent new replies.
	 */
	public function close_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		if ( ! $this->check_permission( 'close_posts', (int) $post->space_id ) ) {
			return $this->permission_error();
		}

		Post::close( $id );

		$updated = Post::find( $id );

		return new WP_REST_Response( $this->prepare_post( $updated ), 200 );
	}

	/**
	 * POST /posts/{id}/pin — Pin (sticky) a post.
	 */
	public function pin_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		if ( ! $this->check_permission( 'pin_posts', (int) $post->space_id ) ) {
			return $this->permission_error();
		}

		// Toggle: unpin if already pinned, pin if not.
		$new_value = $post->is_sticky ? 0 : 1;
		Post::update( $id, [ 'is_sticky' => $new_value ] );

		$updated = Post::find( $id );

		return new WP_REST_Response( $this->prepare_post( $updated ), 200 );
	}

	/**
	 * POST /posts/{id}/move — Move a post to a different space.
	 */
	public function move_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$source_space_id = (int) $post->space_id;
		$target_space_id = absint( $request->get_param( 'target_space_id' ) );

		if ( ! $target_space_id ) {
			return $this->validation_error( __( 'A valid target_space_id is required.', 'jetonomy' ) );
		}

		if ( $source_space_id === $target_space_id ) {
			return $this->validation_error( __( 'Post is already in the target space.', 'jetonomy' ) );
		}

		$target_space = Space::find( $target_space_id );
		if ( ! $target_space ) {
			return $this->not_found( 'Target space' );
		}

		// Require move_posts permission in both source and target spaces.
		if ( ! $this->check_permission( 'move_posts', $source_space_id ) ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'move_posts', $target_space_id ) ) {
			return $this->permission_error();
		}

		// Move the post.
		Post::update( $id, [ 'space_id' => $target_space_id ] );

		// Update post counts on both spaces.
		Space::increment_post_count( $source_space_id, -1 );
		Space::increment_post_count( $target_space_id, 1 );

		$updated = Post::find( $id );

		return new WP_REST_Response( $this->prepare_post( $updated ), 200 );
	}

	/**
	 * Format a post object for API output.
	 *
	 * When called after enrich_with_author() the author fields are already set
	 * on the object — individual DB/cache lookups are skipped in that case.
	 */
	private function prepare_post( object $post ): array {
		$author_id = (int) ( $post->author_id ?? 0 );
		$space     = \Jetonomy\Models\Space::find( (int) $post->space_id );

		// Use pre-enriched data if present, otherwise fall back to per-item lookup.
		if ( isset( $post->author_name ) ) {
			$author_name   = $post->author_name;
			$author_avatar = $post->author_avatar;
			$author_login  = $post->author_login;
			$trust_level   = $post->trust_level;
			$reputation    = $post->reputation;
			$profile_url   = $post->profile_url;
		} else {
			$author        = $author_id ? get_userdata( $author_id ) : null;
			$profile       = $author_id ? \Jetonomy\Models\UserProfile::find_by_user( $author_id ) : null;
			$author_name   = $author ? $author->display_name : __( 'Anonymous', 'jetonomy' );
			$author_avatar = $author ? get_avatar_url( $author_id, [ 'size' => 64 ] ) : '';
			$author_login  = $author ? $author->user_login : '';
			$trust_level   = $profile ? (int) $profile->trust_level : 0;
			$reputation    = $profile ? (int) $profile->reputation : 0;
			$profile_url   = $author_id ? \Jetonomy\get_profile_url( $author_id ) : '';
		}

		return [
			'id'                => (int) $post->id,
			'space_id'          => (int) $post->space_id,
			'author_id'         => $author_id,
			'title'             => $post->title ?? '',
			'slug'              => $post->slug ?? '',
			'content'           => \Jetonomy\Embeds::process( $post->content ?? '' ),
			'content_plain'     => $post->content_plain ?? '',
			'type'              => $post->type ?? 'topic',
			'status'            => $post->status ?? 'publish',
			'is_sticky'         => (bool) ( $post->is_sticky ?? false ),
			'is_closed'         => (bool) ( $post->is_closed ?? false ),
			'is_resolved'       => (bool) ( $post->is_resolved ?? false ),
			'accepted_reply_id' => $post->accepted_reply_id ? (int) $post->accepted_reply_id : null,
			'view_count'        => (int) ( $post->view_count ?? 0 ),
			'reply_count'       => (int) ( $post->reply_count ?? 0 ),
			'vote_score'        => (int) ( $post->vote_score ?? 0 ),
			'last_reply_at'     => $post->last_reply_at ?? null,
			'edited_at'         => $post->edited_at ?? null,
			'edited_by'         => $post->edited_by ? (int) $post->edited_by : null,
			'created_at'        => $post->created_at ?? null,
			'updated_at'        => $post->updated_at ?? null,
			// Enriched author data (for app clients + JS rendering)
			'author_name'       => $author_name,
			'author_avatar'     => $author_avatar,
			'author_login'      => $author_login,
			'trust_level'       => $trust_level,
			'reputation'        => $reputation,
			'time_ago'          => $post->created_at ? human_time_diff( strtotime( $post->created_at ), current_time( 'timestamp', true ) ) . ' ' . __( 'ago', 'jetonomy' ) : '',
			'profile_url'       => $profile_url,
			// Space context
			'space_title'       => $space ? $space->title : '',
			'space_slug'        => $space ? $space->slug : '',
		];
	}

	/**
	 * Generate a unique post slug by appending a numeric suffix if needed.
	 */
	private function unique_post_slug( string $base_slug ): string {
		$slug    = $base_slug;
		$counter = 1;

		while ( Post::find_by_slug( $slug ) ) {
			$slug = $base_slug . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return [
			'title'   => [ 'type' => 'string', 'required' => true ],
			'content' => [ 'type' => 'string', 'required' => true ],
			'type'    => [
				'type'     => 'string',
				'required' => false,
				'enum'     => [ 'topic', 'question', 'discussion', 'announcement', 'idea', 'status' ],
			],
			'tags'    => [
				'type'     => 'array',
				'required' => false,
				'items'    => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Args for update_item (all optional).
	 */
	private function get_update_args(): array {
		return [
			'title'   => [ 'type' => 'string', 'required' => false ],
			'content' => [ 'type' => 'string', 'required' => false ],
		];
	}
}
