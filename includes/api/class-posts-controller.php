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
use Jetonomy\API\REST_Auth;
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
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
					'args'                => array_merge(
						$this->get_collection_params(),
						array(
							'space_id' => array(
								'type'     => 'integer',
								'required' => true,
								'minimum'  => 1,
							),
							// Posts-specific sort enum. Overrides the shared
							// Base_Controller default so `unanswered` (which
							// is a posts-only filter) is advertised here
							// without polluting Bookmarks/Users/Subscriptions
							// list endpoints that inherit the base params.
							'sort'     => array(
								'type'    => 'string',
								'default' => 'latest',
								'enum'    => array( 'latest', 'popular', 'oldest', 'newest', 'unanswered' ),
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
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				),
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

		// Action routes.
		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/close',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'close_post' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);

		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/pin',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pin_post' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);

		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/move',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move_post' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
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
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				'args'                => array(
					'target_post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		// Idea roadmap status — only meaningful on `type=ideas` spaces, gated
		// to space moderators. The post-author cannot self-curate their own
		// status; that's the owner's job.
		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/idea-status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_idea_status' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				'args'                => array(
					'idea_status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Post::valid_idea_statuses(),
					),
				),
			)
		);

		// Link preview — fetch OG metadata for a URL. Public-readable:
		// anonymous visitors to a public post deserve the same rich link
		// preview cards that logged-in members see. The URL being previewed
		// is already visible in the post body, the Preview_Service only
		// scrapes the URL's own OG metadata, and wp_safe_remote_get blocks
		// internal-IP SSRF. Aggressive caching in the service keeps repeated
		// hits cheap.
		register_rest_route(
			$ns,
			'/link-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'link_preview' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
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
	 * GET /link-preview?url= — Rich LinkedIn-style preview metadata for a URL.
	 *
	 * Returns the full Preview_Data shape (title/description/image/site_name/
	 * favicon/embed_html/provider…) — the web card and the mobile app consume
	 * the same response.
	 *
	 * @see \Jetonomy\Services\Links\Preview_Service
	 * @see \Jetonomy\Services\Links\Preview_Data
	 */
	public function link_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$url = (string) $request->get_param( 'url' );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid URL.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		$service = new \Jetonomy\Services\Links\Preview_Service();
		$preview = $service->fetch( $url );

		$response = new \WP_REST_Response( $preview->to_array(), 200 );
		// Clients (web + mobile) can layer their own cache; 10-minute
		// Cache-Control keeps repeat renders cheap without blocking refresh.
		$response->header( 'Cache-Control', 'private, max-age=600' );
		return $response;
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

		// Resolve limit: explicit query-param from client → Space::get_posts_per_page()
		// (space → global → 20). Use raw query params, NOT get_param(), because route
		// schemas auto-fill defaults and would mask the "not provided" case.
		$raw_limit = $request->get_query_params()['limit'] ?? null;
		$has_limit = null !== $raw_limit && '' !== $raw_limit;
		$limit     = $has_limit
			? max( 1, min( 100, (int) $pagination['limit'] ) )
			: Space::get_posts_per_page( $space_id );

		$posts = Post::list_by_space_visible(
			$space_id,
			$user_id,
			$is_privileged,
			$pagination['sort'],
			$limit,
			(int) $pagination['offset'],
			(int) $pagination['after']
		);

		// Eager-load all author data in a single batch before preparing items.
		$posts = $this->enrich_with_author( $posts );

		$items = array_map( array( $this, 'prepare_post' ), $posts );

		return $this->paginated_response(
			$items,
			array(
				'total'  => (int) ( $space->post_count ?? 0 ),
				'offset' => (int) $pagination['offset'],
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

		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$content = wp_kses_post( (string) $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return $this->validation_error( __( 'Post content is required.', 'jetonomy' ) );
		}

		// 1.4.3: every space type collects a user-entered title. Feed-space
		// posts hide the rendered <h1> visually on the single-post page
		// (sr-only) so the surface stays body-first, but the title itself
		// is real data used by breadcrumbs, notifications, search results,
		// emails, and OG share previews.
		if ( empty( $title ) ) {
			return $this->validation_error( __( 'Post title is required.', 'jetonomy' ) );
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

		// Slug generation: title is required (validated above), so slugify
		// it. If sanitize_title yields nothing (e.g. emoji-only title), fall
		// back to a randomised stub so the INSERT never violates the
		// NOT NULL slug column.
		$slug_seed = sanitize_title( $title );
		if ( '' === $slug_seed ) {
			$slug_seed = 'post-' . wp_generate_password( 6, false, false );
		}
		$slug = $this->unique_post_slug( $slug_seed );

		// Akismet spam check — skip for site admins and space admins/moderators.
		// They cannot meaningfully spam their own community and false positives
		// (flagging legitimate staff replies) erode trust in the admin workflow.
		$akismet_spam = false;
		if ( ! $this->author_bypasses_spam_check( $user_id, $space_id ) ) {
			$ip           = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$user         = get_userdata( $user_id );
			$akismet_spam = \Jetonomy\Moderation\Akismet::check_spam(
				$content,
				$user->display_name ?? '',
				$user->user_email ?? '',
				$ip
			);
		}

		$is_private = ! empty( $request->get_param( 'is_private' ) ) ? 1 : 0;

		// Validate prefix against space settings.
		$prefix = sanitize_text_field( (string) $request->get_param( 'prefix' ) );
		if ( ! empty( $prefix ) ) {
			$space_settings   = Space::get_settings( $space_id );
			$allowed_prefixes = ! empty( $space_settings['prefixes'] ) ? array_column( $space_settings['prefixes'], 'name' ) : array();
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

		// Handle draft status — must run before moderation so a draft isn't overridden to 'pending'.
		$requested_status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		if ( 'draft' === $requested_status && ! isset( $post_data['status'] ) ) {
			$post_data['status'] = 'draft';
		}

		// Handle published_at for scheduling (draft) and backdating (publish).
		// Backdating is gated to users with manage_options — seed/import workflows.
		$raw_published_at = $request->get_param( 'published_at' );
		if ( null !== $raw_published_at && '' !== $raw_published_at ) {
			$backdate = $this->sanitize_backdate( $raw_published_at );
			if ( is_wp_error( $backdate ) ) {
				return $backdate;
			}
			if ( null !== $backdate ) {
				$is_publishing = 'draft' !== ( $post_data['status'] ?? 'publish' );
				if ( $is_publishing && ! current_user_can( 'manage_options' ) ) {
					return $this->permission_error();
				}
				$post_data['published_at'] = $backdate;
				if ( $is_publishing ) {
					// For backdated publishes, sync the sort/display columns so listings order correctly.
					$post_data['created_at']    = $backdate;
					$post_data['updated_at']    = $backdate;
					$post_data['last_reply_at'] = $backdate;
				}
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

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

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

		// Backdate: accept published_at to rewrite the date. Gated to manage_options.
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
				$update_data['published_at']  = $backdate;
				$update_data['created_at']    = $backdate;
				$update_data['last_reply_at'] = $backdate;
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

		// Only stamp an "edited" audit trail when the user actually edited content.
		// Pure backdates (published_at/created_at/last_reply_at only) shouldn't appear edited.
		$content_fields = array_intersect_key( $update_data, array_flip( array( 'title', 'content', 'content_plain', 'is_private', 'prefix' ) ) );
		if ( ! empty( $content_fields ) ) {
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
		}

		Post::update( $id, $update_data );

		do_action( 'jetonomy_post_updated', $id, $space_id, $user_id );

		$updated = Post::find( $id );

		/**
		 * Fires after a post is updated. Receives the updated post object plus
		 * a context array (space_id, user_id, request). Listener-friendly variant
		 * of `jetonomy_post_updated` for extensions that need the full object.
		 *
		 * @since 1.4.1
		 * @param object          $updated Post object.
		 * @param array{space_id:int,user_id:int,request:WP_REST_Request} $context Context.
		 */
		do_action(
			'jetonomy_after_update_post',
			$updated,
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'request'  => $request,
			)
		);

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

		// Post::update() detects the publish→trash transition and decrements
		// space/user counters atomically — no manual pre-decrement needed.
		Post::update( $id, array( 'status' => 'trash' ) );

		do_action( 'jetonomy_post_deleted', $id, $space_id, $user_id );

		/**
		 * Fires after a post is deleted. Receives only the deleted post ID.
		 *
		 * @since 1.4.1
		 * @param int $id Deleted post ID.
		 */
		do_action( 'jetonomy_after_delete_post', $id );

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
	 * POST /posts/{id}/idea-status — Set the roadmap status for an idea.
	 *
	 * Only valid on `type=ideas` spaces. Other space types get 400 so a
	 * moderator with API access can't accidentally pollute non-Ideas posts
	 * with a status field that means nothing for those types.
	 */
	public function set_idea_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id   = absint( $request->get_param( 'id' ) );
		$post = Post::find( $id );

		if ( ! $post ) {
			return $this->not_found( 'Post' );
		}

		$space = \Jetonomy\Models\Space::find( (int) $post->space_id );
		if ( ! $space || 'ideas' !== ( $space->type ?? '' ) ) {
			return new \WP_Error(
				'jetonomy_not_ideas_space',
				__( 'Roadmap status only applies to Ideas spaces.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->check_permission( 'close_posts', (int) $post->space_id ) ) {
			return $this->permission_error();
		}

		$status          = sanitize_key( (string) $request->get_param( 'idea_status' ) );
		$previous_status = (string) ( $post->idea_status ?? '' );
		// `set_idea_status` is the canonical write site — it fires
		// `jetonomy_idea_status_changed` itself so the seeder / CLI /
		// abilities paths emit the same event without coupling each
		// caller to do_action.
		if ( ! Post::set_idea_status( $id, $status, (int) $user_id ) ) {
			return $this->validation_error( __( 'Invalid roadmap status.', 'jetonomy' ) );
		}

		// Reward author when status transitions TO 'planned'. Idempotent on
		// no-op changes (planned → planned awards nothing) and skips
		// self-curated ideas (author == actor) so a moderator can't farm
		// reputation by re-planning their own idea.
		if ( 'planned' === $status && 'planned' !== $previous_status && ! empty( $post->author_id ) ) {
			$author_id = (int) $post->author_id;
			if ( $author_id !== (int) $user_id ) {
				\Jetonomy\Trust\Reputation::award( $author_id, 'idea_planned' );
			}
		}

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

		$data = array(
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
			'idea_status'       => isset( $post->idea_status ) && '' !== (string) $post->idea_status ? (string) $post->idea_status : null,
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

		/**
		 * Filter the REST response data for a single post.
		 *
		 * @param array  $data    Prepared response data.
		 * @param object $post    Raw post row object.
		 * @param null   $request WP_REST_Request (null in non-request contexts).
		 */
		$data = apply_filters( 'jetonomy_rest_prepare_post', $data, $post, null );

		/**
		 * Alias filter matching the Pro custom-fields listener contract —
		 * lets extensions append per-post payload (custom field values, etc.)
		 * to the API response. Context carries object_type + object_id so
		 * generic extensions can route on type.
		 *
		 * @since 1.4.1
		 * @param array $data    Prepared response data.
		 * @param array $context { object_type: 'post', object_id: int }
		 */
		$data = apply_filters(
			'jetonomy_post_response',
			$data,
			array(
				'object_type' => 'post',
				'object_id'   => (int) $post->id,
			)
		);

		return $data;
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
			// Title is optional at the REST layer; the handler still
			// requires it for non-Feed spaces and derives it from content
			// for Feed spaces. Marking it `required: true` here would
			// short-circuit the body-derived title path before reaching
			// the handler.
			'title'        => array(
				'type'     => 'string',
				'required' => false,
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
			'title'        => array(
				'type'     => 'string',
				'required' => false,
			),
			'content'      => array(
				'type'     => 'string',
				'required' => false,
			),
			'is_private'   => array(
				'type'     => 'boolean',
				'required' => false,
			),
			'prefix'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'published_at' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
