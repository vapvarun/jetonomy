<?php
/**
 * Posts REST API controller.
 *
 * @package Jetonomy
 */

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
		register_rest_route(
			$ns,
			'/spaces/(?P<space_id>\d+)/posts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_collection_params(),
						array(
							'space_id' => array(
								'type'     => 'integer',
								'required' => true,
								'minimum'  => 1,
							),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => [ $this, 'login_permission_check' ],
					'args'                => $this->get_create_args(),
				),
			)
		);

		// Drafts list for the current user.
		register_rest_route(
			$ns,
			'/posts/drafts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_drafts' ),
				'permission_callback' => '__return_true',
			)
		);

		// Single-item routes.
		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => [ $this, 'login_permission_check' ],
					'args'                => $this->get_update_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => [ $this, 'login_permission_check' ],
				),
			)
		);

		// Action routes.
		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/close',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'close_post' ),
				'permission_callback' => [ $this, 'login_permission_check' ],
			)
		);

		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/pin',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pin_post' ),
				'permission_callback' => [ $this, 'login_permission_check' ],
			)
		);

		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/move',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move_post' ),
				'permission_callback' => [ $this, 'login_permission_check' ],
				'args'                => array(
					'target_space_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/merge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'merge_post' ),
				'permission_callback' => [ $this, 'login_permission_check' ],
				'args'                => array(
					'target_post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		// Link preview — fetch OG metadata for a URL.
		register_rest_route(
			$ns,
			'/link-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'link_preview' ),
				'permission_callback' => [ $this, 'login_permission_check' ],
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * GET /link-preview?url= — Fetch OG metadata for a URL.
	 */
	public function link_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$url = $request->get_param( 'url' );
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', 'Invalid URL.', array( 'status' => 400 ) );
		}

		// Check transient cache first.
		$cache_key = 'jetonomy_og_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached, 200 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
				'headers'   => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'title'       => '',
					'description' => '',
					'image'       => '',
					'domain'      => '',
				),
				200
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = array(
			'title'       => '',
			'description' => '',
			'image'       => '',
			'domain'      => wp_parse_url( $url, PHP_URL_HOST ) ?: '',
		);

		// Parse OG tags.
		if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $body, $m ) ) {
			$data['title'] = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		} elseif ( preg_match( '/<title[^>]*>([^<]+)/i', $body, $m ) ) {
			$data['title'] = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)/i', $body, $m ) ) {
			$data['description'] = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		} elseif ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $body, $m ) ) {
			$data['description'] = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $body, $m ) ) {
			$data['image'] = esc_url_raw( $m[1] );
		}

		// Cache for 24 hours.
		set_transient( $cache_key, $data, DAY_IN_SECONDS );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /posts/drafts — List the current user's draft posts.
	 */
	public function list_drafts( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$drafts = Post::list_drafts_by_user( $user_id );
		$items  = array_map( array( $this, 'prepare_post' ), $drafts );

		return new WP_REST_Response( array( 'data' => $items ), 200 );
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

		$user_id       = get_current_user_id();
		$is_privileged = \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $user_id, $space_id );

		$pagination = $this->get_pagination( $request );

		// Pass -1 when client didn't send a limit so the model resolves from space/global settings.
		$limit = null !== $request->get_param( 'limit' ) ? (int) $pagination['limit'] : -1;

		$posts = Post::list_by_space_visible(
			$space_id,
			$user_id,
			$is_privileged,
			$pagination['sort'],
			$limit,
			(int) $pagination['offset'],
			(int) $pagination['after']
		);

		// Resolve effective limit for pagination metadata.
		$effective_limit = count( $posts );
		if ( -1 === $limit ) {
			$space_settings  = Space::get_settings( $space_id );
			$effective_limit = ! empty( $space_settings['posts_per_page'] ) ? (int) $space_settings['posts_per_page'] : 0;
			if ( $effective_limit <= 0 ) {
				$global          = get_option( 'jetonomy_settings', array() );
				$effective_limit = (int) ( $global['posts_per_page'] ?? 20 );
			}
		} else {
			$effective_limit = (int) $pagination['limit'];
		}

		// Eager-load all author data in a single batch before preparing items.
		$posts = $this->enrich_with_author( $posts );

		$items = array_map( array( $this, 'prepare_post' ), $posts );

		return $this->paginated_response(
			$items,
			array(
				'total'    => (int) ( $space->post_count ?? 0 ),
				'has_more' => count( $items ) === $effective_limit,
			)
		);
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

		// Combined space + post-level visibility check.
		if ( ! \Jetonomy\Permissions\Permission_Engine::can_read_post( get_current_user_id(), $post ) ) {
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
		if ( in_array( $space->status ?? '', array( 'archived', 'locked' ), true ) ) {
			return new WP_Error(
				'jetonomy_space_restricted',
				__( 'This space is archived or locked and no longer accepts new posts.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		// Rate limit check.
		$profile = UserProfile::find_or_create( $user_id );
		$trust   = (int) ( $profile->trust_level ?? 0 );
		if ( ! \Jetonomy\Permissions\Rate_Limiter::check( $user_id, 'create_posts', $trust ) ) {
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
			$space_type_to_post_type = array(
				'qa'    => 'question',
				'ideas' => 'idea',
				'feed'  => 'status',
			);
			$space_type              = $space->type ?? 'forum';
			$type                    = $space_type_to_post_type[ $space_type ] ?? 'topic';
		}

		$content_plain = wp_strip_all_tags( $content );
		$slug          = $this->unique_post_slug( sanitize_title( $title ) );

		// Akismet spam check.
		$ip           = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user         = get_userdata( $user_id );
		$akismet_spam = \Jetonomy\Moderation\Akismet::check_spam(
			$content,
			$user->display_name ?? '',
			$user->user_email ?? '',
			$ip
		);

		$is_private = ! empty( $request->get_param( 'is_private' ) ) ? 1 : 0;

		// Validate prefix against space settings.
		$prefix = sanitize_text_field( (string) $request->get_param( 'prefix' ) );
		if ( ! empty( $prefix ) ) {
			$space_settings    = Space::get_settings( $space_id );
			$allowed_prefixes  = ! empty( $space_settings['prefixes'] ) ? array_column( $space_settings['prefixes'], 'name' ) : array();
			if ( ! in_array( $prefix, $allowed_prefixes, true ) ) {
				$prefix = '';
			}
		}

		$post_data = array(
			'space_id'      => $space_id,
			'author_id'     => $user_id,
			'title'         => $title,
			'slug'          => $slug,
			'content'       => $content,
			'content_plain' => $content_plain,
			'type'          => $type,
			'is_private'    => $is_private,
		);

		if ( ! empty( $prefix ) ) {
			$post_data['prefix'] = $prefix;
		}

		if ( $akismet_spam ) {
			$post_data['status'] = 'spam';
		}

		// Handle draft/schedule — must run before moderation so a draft isn't overridden to 'pending'.
		$requested_status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		if ( 'draft' === $requested_status && ! isset( $post_data['status'] ) ) {
			$post_data['status'] = 'draft';
			$scheduled_at        = sanitize_text_field( (string) $request->get_param( 'published_at' ) );
			if ( ! empty( $scheduled_at ) ) {
				$post_data['published_at'] = $scheduled_at;
			}
		}

		// Skip moderation pipeline for draft posts — they're not published yet.
		if ( 'draft' !== ( $post_data['status'] ?? '' ) ) {
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

			// Per-space require_approval: hold for moderation unless moderator/admin.
			if ( empty( $post_data['status'] ) || 'publish' === ( $post_data['status'] ?? '' ) ) {
				$space_settings = Space::get_settings( $space_id );
				if ( ! empty( $space_settings['require_approval'] ) ) {
					$member_role = \Jetonomy\Models\SpaceMember::get_role( $space_id, $user_id );
					if ( ! in_array( $member_role, array( 'moderator', 'admin' ), true ) && ! current_user_can( 'manage_options' ) ) {
						$post_data['status'] = 'pending';
					}
				}
			}
		}

		$post_id = Post::create( $post_data );

		if ( ! $post_id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create post.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}

		// Fire action for Activity_Tracker, Notifier, and other listeners.
		// Skip for draft posts — they are not visible yet.
		if ( 'draft' !== ( $post_data['status'] ?? 'publish' ) ) {
			do_action( 'jetonomy_after_create_post', $post_id, $space_id, $request );
		}

		// Note: UserProfile::increment_post_count is handled inside Post::create() for published
		// posts. The call below is intentionally removed to prevent double-counting.

		// Increment rate limit counter.
		\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'create_posts' );

		// Auto-subscribe the author — only for published/pending posts, not drafts.
		if ( 'draft' !== ( $post_data['status'] ?? 'publish' ) ) {
			Subscription::subscribe( $user_id, 'post', $post_id );
		}

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

		// Parse @mentions and notify — only for published posts.
		if ( 'draft' !== ( $post_data['status'] ?? 'publish' ) ) {
			$mentioned = \Jetonomy\Mentions::extract_user_ids( $content );
			if ( ! empty( $mentioned ) ) {
				\Jetonomy\Mentions::notify( $mentioned, $user_id, 'post', $post_id, $title );
			}
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

		$update_data = array();

		if ( null !== $request->get_param( 'title' ) ) {
			$update_data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}

		if ( null !== $request->get_param( 'content' ) ) {
			$content                      = wp_kses_post( $request->get_param( 'content' ) );
			$update_data['content']       = $content;
			$update_data['content_plain'] = wp_strip_all_tags( $content );
		}

		if ( null !== $request->get_param( 'is_private' ) ) {
			$update_data['is_private'] = ! empty( $request->get_param( 'is_private' ) ) ? 1 : 0;
		}

		if ( null !== $request->get_param( 'prefix' ) ) {
			$prefix_val = sanitize_text_field( (string) $request->get_param( 'prefix' ) );
			if ( '' === $prefix_val ) {
				// Explicitly clear prefix.
				$update_data['prefix'] = null;
			} else {
				$space_settings   = Space::get_settings( $space_id );
				$allowed_prefixes = ! empty( $space_settings['prefixes'] ) ? array_column( $space_settings['prefixes'], 'name' ) : array();
				if ( in_array( $prefix_val, $allowed_prefixes, true ) ) {
					$update_data['prefix'] = $prefix_val;
				}
			}
		}

		if ( empty( $update_data ) ) {
			return $this->validation_error( __( 'No fields provided for update.', 'jetonomy' ) );
		}

		// Advanced Moderation: check updated content.
		$check_data        = array_intersect_key( $update_data, array_flip( array( 'title', 'content' ) ) );
		$moderation_action = apply_filters( 'jetonomy_check_content', null, $check_data, $space_id, $user_id );
		if ( 'block' === $moderation_action ) {
			return $this->validation_error( __( 'Your post was blocked by our content policy.', 'jetonomy' ) );
		}

		// Create a revision before updating.
		Revision::create(
			array(
				'object_type' => 'post',
				'object_id'   => $id,
				'author_id'   => $user_id,
				'content'     => $post->content ?? '',
				'title'       => $post->title ?? '',
			)
		);

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

		// Decrement denormalized counters before soft-deleting.
		Space::increment_post_count( $space_id, -1 );
		UserProfile::increment_post_count( (int) $post->author_id, -1 );

		Post::update( $id, array( 'status' => 'trash' ) );

		do_action( 'jetonomy_post_deleted', $id, $space_id, $user_id );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
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
		Post::update( $id, array( 'is_sticky' => $new_value ) );

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
		Post::update( $id, array( 'space_id' => $target_space_id ) );

		// Update post counts on both spaces.
		Space::increment_post_count( $source_space_id, -1 );
		Space::increment_post_count( $target_space_id, 1 );

		$updated = Post::find( $id );

		return new WP_REST_Response( $this->prepare_post( $updated ), 200 );
	}

	/**
	 * POST /posts/{id}/merge — Merge this post into another.
	 */
	public function merge_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$source_id = absint( $request->get_param( 'id' ) );
		$source    = Post::find( $source_id );

		if ( ! $source ) {
			return $this->not_found( 'Post' );
		}

		$target_id = absint( $request->get_param( 'target_post_id' ) );
		$target    = Post::find( $target_id );

		if ( ! $target ) {
			return $this->not_found( 'Target post' );
		}

		if ( $source_id === $target_id ) {
			return $this->validation_error( __( 'Cannot merge a post with itself.', 'jetonomy' ) );
		}

		// Require moderator permission in both spaces.
		if ( ! $this->check_permission( 'move_posts', (int) $source->space_id ) ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'move_posts', (int) $target->space_id ) ) {
			return $this->permission_error();
		}

		$success = Post::merge_into( $source_id, $target_id );

		if ( ! $success ) {
			return new WP_Error(
				'jetonomy_merge_failed',
				__( 'Failed to merge topics.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}

		$updated = Post::find( $target_id );

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
			$author_avatar = $author ? get_avatar_url( $author_id, array( 'size' => 64 ) ) : '';
			$author_login  = $author ? $author->user_login : '';
			$trust_level   = $profile ? (int) $profile->trust_level : 0;
			$reputation    = $profile ? (int) $profile->reputation : 0;
			$profile_url   = $author_id ? \Jetonomy\get_profile_url( $author_id ) : '';
		}

		// Resolve prefix color from space settings.
		$prefix_name  = $post->prefix ?? null;
		$prefix_color = null;
		if ( $prefix_name && $space ) {
			$space_settings = Space::get_settings( (int) $space->id );
			$prefix_list    = $space_settings['prefixes'] ?? array();
			foreach ( $prefix_list as $pfx ) {
				if ( ( $pfx['name'] ?? '' ) === $prefix_name ) {
					$prefix_color = $pfx['color'] ?? null;
					break;
				}
			}
		}

		return array(
			'id'                => (int) $post->id,
			'space_id'          => (int) $post->space_id,
			'author_id'         => $author_id,
			'prefix'            => $prefix_name,
			'prefix_color'      => $prefix_color,
			'title'             => $post->title ?? '',
			'slug'              => $post->slug ?? '',
			'content'           => \Jetonomy\Embeds::process( $post->content ?? '' ),
			'content_plain'     => $post->content_plain ?? '',
			'type'              => $post->type ?? 'topic',
			'status'            => $post->status ?? 'publish',
			'is_sticky'         => (bool) ( $post->is_sticky ?? false ),
			'is_private'        => (bool) ( $post->is_private ?? false ),
			'is_closed'         => (bool) ( $post->is_closed ?? false ),
			'is_resolved'       => (bool) ( $post->is_resolved ?? false ),
			'accepted_reply_id' => $post->accepted_reply_id ? (int) $post->accepted_reply_id : null,
			'view_count'        => (int) ( $post->view_count ?? 0 ),
			'reply_count'       => (int) ( $post->reply_count ?? 0 ),
			'vote_score'        => (int) ( $post->vote_score ?? 0 ),
			'last_reply_at'     => $post->last_reply_at ?? null,
			'edited_at'         => $post->edited_at ?? null,
			'edited_by'         => $post->edited_by ? (int) $post->edited_by : null,
			'published_at'      => $post->published_at ?? null,
			'created_at'        => $post->created_at ?? null,
			'updated_at'        => $post->updated_at ?? null,
			// Enriched author data (for app clients + JS rendering)
			'author_name'       => $author_name,
			'author_avatar'     => $author_avatar,
			'author_login'      => $author_login,
			'trust_level'       => $trust_level,
			'reputation'        => $reputation,
			'time_ago'          => $post->created_at ? human_time_diff( strtotime( $post->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '',
			'profile_url'       => $profile_url,
			// Space context
			'space_title'       => $space ? $space->title : '',
			'space_slug'        => $space ? $space->slug : '',
		);
	}

	/**
	 * Generate a unique post slug by appending a numeric suffix if needed.
	 */
	private function unique_post_slug( string $base_slug ): string {
		$slug    = $base_slug;
		$counter = 1;

		while ( Post::find_by_slug( $slug ) ) {
			$slug = $base_slug . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return array(
			'title'        => array(
				'type'     => 'string',
				'required' => true,
			),
			'content'      => array(
				'type'     => 'string',
				'required' => true,
			),
			'type'         => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'topic', 'question', 'discussion', 'announcement', 'idea', 'status' ),
			),
			'tags'         => array(
				'type'     => 'array',
				'required' => false,
				'items'    => array( 'type' => 'string' ),
			),
			'prefix'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'is_private'   => array(
				'type'     => 'boolean',
				'required' => false,
				'default'  => false,
			),
			'status'       => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'publish', 'draft' ),
				'default'  => 'publish',
			),
			'published_at' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Args for update_item (all optional).
	 */
	private function get_update_args(): array {
		return array(
			'title'      => array(
				'type'     => 'string',
				'required' => false,
			),
			'content'    => array(
				'type'     => 'string',
				'required' => false,
			),
			'is_private' => array(
				'type'     => 'boolean',
				'required' => false,
			),
			'prefix'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
