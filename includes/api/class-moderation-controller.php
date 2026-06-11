<?php
/**
 * Moderation REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;
use Jetonomy\Trust\Reputation;
use function Jetonomy\table;

class Moderation_Controller extends Base_Controller {

	protected $rest_base = 'moderation';

	/**
	 * Register REST routes for moderation.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// GET moderation queue. Accepts `status=pending|spam|all` (default: pending)
		// so moderators can see the spam bucket without opening wp-admin.
		register_rest_route(
			$ns,
			'/moderation/queue',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => [ $this, 'require_moderate' ],
				'args'                => [
					'status' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => 'pending',
						'enum'              => [ 'pending', 'spam', 'all' ],
						'sanitize_callback' => 'sanitize_key',
					],
					'type'   => [
						'type'              => 'string',
						'required'          => false,
						'enum'              => [ 'post', 'reply' ],
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		// Approve a post or reply.
		register_rest_route(
			$ns,
			'/moderation/approve/(?P<type>post|reply)/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'approve' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_moderate' ),
			]
		);

		// Mark a post or reply as spam.
		register_rest_route(
			$ns,
			'/moderation/spam/(?P<type>post|reply)/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_spam' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_moderate' ),
			]
		);

		// Create a flag report (requires jetonomy_flag, not moderate).
		// Silenced-user gate runs inside the handler — REST_Auth covers
		// login + nonce + cap, the silenced check needs to read a Restriction
		// row that is too business-rule heavy to live in the auth helper.
		register_rest_route(
			$ns,
			'/flags',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_flag' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_flag' ),
				'args'                => [
					'object_type' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'post', 'reply', 'user' ],
					],
					'object_id'   => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
					'reason'      => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'spam', 'offensive', 'off_topic', 'harassment', 'other' ],
					],
					'description' => [
						'type'     => 'string',
						'required' => false,
					],
				],
			]
		);

		// List pending flags.
		register_rest_route(
			$ns,
			'/moderation/flags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_flags' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);

		// Trash content.
		register_rest_route(
			$ns,
			'/moderation/trash/(?P<type>post|reply)/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'trash_content' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_moderate' ),
			]
		);

		// Resolve flag.
		register_rest_route(
			$ns,
			'/moderation/flags/(?P<id>\d+)/resolve',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'resolve_flag' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_moderate' ),
				'args'                => [
					'status' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'valid', 'dismissed' ],
					],
				],
			]
		);

		// Bulk moderation action — REST parity with the wp-admin AJAX handler
		// `wp_ajax_jetonomy_bulk_content_action` (1.4.1 A4). The AJAX handler
		// stays in place for the wp-admin Content page; this endpoint exposes
		// the same operation to frontend custom-mod tooling that talks REST.
		register_rest_route(
			$ns,
			'/moderation/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_action' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_moderate' ),
				'args'                => [
					'action' => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => [ 'approve', 'spam', 'trash' ],
						'sanitize_callback' => 'sanitize_key',
					],
					'items'  => [
						'type'     => 'array',
						'required' => true,
					],
				],
			]
		);

		// Per-post flag inspection (1.4.1 A5). Mods looking at a specific post
		// shouldn't have to filter the global flags list to see what was
		// reported on that post — this returns an empty array (200), never 404,
		// when a post has no flags.
		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/flags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_flags' ],
				'permission_callback' => [ $this, 'require_moderate' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
				],
			]
		);

		// Ban user — REST_Auth handles login + cookie nonce only. The cap
		// matrix here is too contextual for the helper: global bans + silences
		// require jetonomy_moderate / manage_options, but space_ban also accepts
		// the per-space admin role from the supplied body `space_id`. The
		// detailed gate runs inside ban_user() once the request body has been
		// parsed; REST_Auth still blocks anonymous + un-nonced requests at the
		// route layer so unauthenticated callers never reach the handler.
		register_rest_route(
			$ns,
			'/moderation/ban',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ban_user' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				'args'                => [
					'user_id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'type'       => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'global_ban', 'space_ban', 'silence' ],
					],
					'reason'     => [ 'type' => 'string' ],
					'space_id'   => [ 'type' => 'integer' ],
					'expires_at' => [ 'type' => 'string' ],
				],
			]
		);

		// Unban user.
		register_rest_route(
			$ns,
			'/moderation/ban/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'unban_user' ],
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_moderate' ),
			]
		);
	}

	/**
	 * Permission callback: requires jetonomy_moderate capability.
	 */
	public function require_moderate(): bool|WP_Error {
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * GET /moderation/queue — Fetch pending posts, replies, and flag count.
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$posts_table   = table( 'posts' );
		$replies_table = table( 'replies' );

		$status_filter = (string) $request->get_param( 'status' );
		$type_filter   = (string) $request->get_param( 'type' );

		$status_clause = 'all' === $status_filter
			? "status IN ('pending','spam')"
			: $wpdb->prepare( 'status = %s', $status_filter );

		$posts = array();
		if ( '' === $type_filter || 'post' === $type_filter ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$posts = $wpdb->get_results(
				"SELECT *, 'post' AS object_type FROM {$posts_table} WHERE {$status_clause} ORDER BY created_at DESC"
			) ?: array();
		}

		$replies = array();
		if ( '' === $type_filter || 'reply' === $type_filter ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$replies = $wpdb->get_results(
				"SELECT *, 'reply' AS object_type FROM {$replies_table} WHERE {$status_clause} ORDER BY created_at DESC"
			) ?: array();
		}

		// Merge and sort by created_at DESC.
		$merged = array_merge( $posts, $replies );
		usort(
			$merged,
			function ( $a, $b ) {
				return strcmp( $b->created_at, $a->created_at );
			}
		);

		$pending_flags_count = Flag::count_pending();

		return new WP_REST_Response(
			array(
				'data'                => $merged,
				'pending_flags_count' => $pending_flags_count,
				'meta'                => array(
					'count'    => count( $merged ),
					'status'   => $status_filter,
					'type'     => $type_filter !== '' ? $type_filter : null,
					'has_more' => false,
				),
			),
			200
		);
	}

	/**
	 * POST /moderation/approve/{type}/{id} — Approve a pending post or reply.
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );
		$id   = absint( $request->get_param( 'id' ) );

		$result = $this->approve_item( $type, $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'approved'    => true,
				'object_type' => $type,
				'id'          => $id,
			],
			200
		);
	}

	/**
	 * POST /moderation/spam/{type}/{id} — Mark a post or reply as spam.
	 *
	 * Applies a -20 reputation penalty to the author.
	 */
	public function mark_spam( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );
		$id   = absint( $request->get_param( 'id' ) );

		$result = $this->spam_item( $type, $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'marked_spam' => true,
				'object_type' => $type,
				'id'          => $id,
			],
			200
		);
	}

	/**
	 * POST /flags — Create a flag report.
	 *
	 * REST_Auth covers login + nonce + the `jetonomy_flag` cap. The silenced-user
	 * check stays in the handler because it queries a Restriction row — a
	 * business-rule layer that doesn't belong in the auth helper.
	 */
	public function create_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		if ( Restriction::is_silenced( $user_id ) ) {
			return new WP_Error( 'silenced', __( 'You are currently silenced.', 'jetonomy' ), [ 'status' => 403 ] );
		}

		$object_type = sanitize_text_field( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );
		$reason      = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$description = sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?? '' ) );

		// Prevent duplicate flags by the same user on the same object.
		$existing = Flag::find_by_reporter_and_object( $user_id, $object_type, $object_id );
		if ( $existing ) {
			return new WP_Error(
				'jetonomy_already_flagged',
				__( 'You have already reported this content.', 'jetonomy' ),
				[ 'status' => 409 ]
			);
		}

		$flag_id = Flag::create(
			[
				'reporter_id' => $user_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'reason'      => $reason,
				'description' => $description,
			]
		);

		if ( ! $flag_id ) {
			return new WP_Error(
				'jetonomy_flag_failed',
				__( 'Failed to create flag.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		do_action( 'jetonomy_flag_created', $flag_id, $object_type );

		// Award reputation impact to reported content author (skip self-reports).
		// Fires for post/reply targets only; user-targeted flags don't deduct
		// reputation from the reported user — those go through the ban flow.
		$author_id = 0;
		if ( 'post' === $object_type ) {
			$reported_post = \Jetonomy\Models\Post::find( (int) $object_id );
			if ( $reported_post ) {
				$author_id = (int) $reported_post->author_id;
			}
		} elseif ( 'reply' === $object_type ) {
			$reported_reply = \Jetonomy\Models\Reply::find( (int) $object_id );
			if ( $reported_reply ) {
				$author_id = (int) $reported_reply->author_id;
			}
		}

		if ( $author_id > 0 && $author_id !== (int) $user_id ) {
			Reputation::award( $author_id, 'post_reported' );
		}

		/**
		 * Fires after a flag is created with the full flag object plus context.
		 *
		 * @since 1.4.1
		 * @param object          $flag    Flag object (id, reporter_id, object_type, object_id, reason, description).
		 * @param array{user_id:int,request:WP_REST_Request} $context Context.
		 */
		$flag = Flag::find( (int) $flag_id );
		if ( $flag ) {
			do_action(
				'jetonomy_after_create_flag',
				$flag,
				array(
					'user_id' => $user_id,
					'request' => $request,
				)
			);
		}

		return new WP_REST_Response(
			[
				'created' => true,
				'id'      => $flag_id,
			],
			201
		);
	}

	/**
	 * GET /moderation/flags — List all pending flags.
	 */
	public function list_flags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$flags = Flag::list_pending();

		return $this->paginated_response(
			$flags,
			[
				'total'    => count( $flags ),
				'has_more' => false,
			]
		);
	}

	/**
	 * POST /moderation/trash/{type}/{id} — Trash (soft-delete) a post or reply.
	 */
	public function trash_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );
		$id   = absint( $request->get_param( 'id' ) );

		$result = $this->trash_item( $type, $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'trashed'     => true,
				'object_type' => $type,
				'id'          => $id,
			],
			200
		);
	}

	/**
	 * POST /moderation/bulk — Apply approve/spam/trash across many items in one call (1.4.1 A4).
	 *
	 * REST parity with the wp-admin AJAX path `wp_ajax_jetonomy_bulk_content_action`.
	 * Each item is dispatched through the same per-item helper used by the single-item
	 * routes so any side effect (reputation penalty, `jetonomy_content_moderated`
	 * action, future notify-reporter logic) fires identically.
	 *
	 * Response is always 200 with a per-item `results` array; individual item
	 * failures are reported in the row's `status` field rather than aborting
	 * the whole batch (a moderator approving 50 comments shouldn't lose progress
	 * because one row was already deleted by another mod).
	 */
	public function bulk_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$items  = $request->get_param( 'items' );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return $this->validation_error( __( 'items must be a non-empty array.', 'jetonomy' ) );
		}

		if ( ! in_array( $action, [ 'approve', 'spam', 'trash' ], true ) ) {
			return $this->validation_error( __( 'Invalid action; expected approve, spam, or trash.', 'jetonomy' ) );
		}

		$results = [];
		foreach ( $items as $item ) {
			// Tolerate both array and stdClass payloads (REST decodes JSON to assoc arrays
			// by default, but PHP arg passing through internal callers can yield objects).
			$type = '';
			$id   = 0;
			if ( is_array( $item ) ) {
				$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
				$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			} elseif ( is_object( $item ) ) {
				$type = isset( $item->type ) ? sanitize_key( (string) $item->type ) : '';
				$id   = isset( $item->id ) ? absint( $item->id ) : 0;
			}

			if ( ! in_array( $type, [ 'post', 'reply' ], true ) || $id <= 0 ) {
				$results[] = [
					'type'   => $type,
					'id'     => $id,
					'status' => 'invalid_item',
				];
				continue;
			}

			$result = match ( $action ) {
				'approve' => $this->approve_item( $type, $id ),
				'spam'    => $this->spam_item( $type, $id ),
				'trash'   => $this->trash_item( $type, $id ),
			};

			if ( is_wp_error( $result ) ) {
				$results[] = [
					'type'   => $type,
					'id'     => $id,
					'status' => $result->get_error_code() ?: 'error',
				];
			} else {
				$results[] = [
					'type'   => $type,
					'id'     => $id,
					'status' => 'ok',
				];
			}
		}

		return new WP_REST_Response(
			[
				'action'  => $action,
				'results' => $results,
			],
			200
		);
	}

	/**
	 * GET /posts/{id}/flags — List all flags filed against a single post (1.4.1 A5).
	 *
	 * Mod-only inspection view. Returns `[]` (200) — never 404 — when the post
	 * has no flags, because the resource (the post) exists and the relationship
	 * "this post's flags" is simply empty.
	 */
	public function get_post_flags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );

		if ( $post_id <= 0 ) {
			return $this->validation_error( __( 'Invalid post id.', 'jetonomy' ) );
		}

		if ( ! \Jetonomy\Models\Post::find( $post_id ) ) {
			return $this->not_found( 'Post' );
		}

		$flags = Flag::find_for_post( $post_id );

		return new WP_REST_Response( $flags, 200 );
	}

	/**
	 * POST /moderation/flags/{id}/resolve — Resolve a flag as valid or dismissed.
	 */
	public function resolve_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );

		$flag = Flag::find( $id );
		if ( ! $flag ) {
			return $this->not_found( 'Flag' );
		}

		$resolved = Flag::resolve( $id, get_current_user_id(), $status );

		if ( ! $resolved ) {
			return new WP_Error(
				'jetonomy_resolve_failed',
				__( 'Failed to resolve flag.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'resolved' => true,
				'id'       => $id,
				'status'   => $status,
			],
			200
		);
	}

	/**
	 * POST /moderation/ban — Issue a ban, space ban, or silence.
	 *
	 * REST_Auth gates this on `jetonomy_moderate` OR `manage_options`. Space
	 * admins legitimately need to issue `space_ban` for their own space even
	 * without those caps, so the route-level callback above accepts the broad
	 * pair and we re-resolve the space-admin delegation here. Global bans /
	 * silences always require the upfront caps; space-bans accept space-admins.
	 */
	public function ban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id    = absint( $request->get_param( 'user_id' ) );
		$type       = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$reason     = $request->get_param( 'reason' ) ? sanitize_textarea_field( (string) $request->get_param( 'reason' ) ) : null;
		$space_id   = $request->get_param( 'space_id' ) ? absint( $request->get_param( 'space_id' ) ) : null;
		$expires_at = $request->get_param( 'expires_at' ) ? sanitize_text_field( (string) $request->get_param( 'expires_at' ) ) : null;

		// Ban cap matrix:
		// - jetonomy_moderate OR manage_options always passes.
		// - space_ban additionally accepts the space-admin delegate from the
		// body-supplied space_id.
		// Global bans / silences without either cap are rejected.
		$actor_id = get_current_user_id();
		$has_caps = current_user_can( 'jetonomy_moderate' ) || current_user_can( 'manage_options' );
		if ( ! $has_caps ) {
			if ( 'space_ban' !== $type || ! $space_id
				|| ! \Jetonomy\Permissions\Permission_Engine::is_space_admin( $actor_id, $space_id ) ) {
				return $this->permission_error();
			}
		}

		if ( ! get_userdata( $user_id ) ) {
			return $this->not_found( 'User' );
		}

		$restriction_id = Restriction::ban(
			$user_id,
			$type,
			get_current_user_id(),
			$space_id,
			$reason,
			$expires_at
		);

		if ( ! $restriction_id ) {
			return new WP_Error(
				'jetonomy_ban_failed',
				__( 'Failed to issue restriction.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'banned'         => true,
				'restriction_id' => $restriction_id,
				'user_id'        => $user_id,
				'type'           => $type,
			],
			201
		);
	}

	/**
	 * DELETE /moderation/ban/{id} — Lift a restriction by its ID.
	 */
	public function unban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		$restriction = Restriction::find( $id );
		if ( ! $restriction ) {
			return $this->not_found( 'Restriction' );
		}

		$removed = Restriction::remove_ban( $id );

		if ( ! $removed ) {
			return new WP_Error(
				'jetonomy_unban_failed',
				__( 'Failed to remove restriction.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'removed' => true,
				'id'      => $id,
			],
			200
		);
	}

	/**
	 * Set the status of a post or reply by ID.
	 *
	 * @param string $type   'post' or 'reply'.
	 * @param int    $id     Row ID.
	 * @param string $status New status value.
	 * @return bool|WP_Error
	 */
	private function set_status( string $type, int $id, string $status ): bool|WP_Error {
		if ( 'post' === $type ) {
			if ( ! \Jetonomy\Models\Post::find( $id ) ) {
				return $this->not_found( 'Post' );
			}
			\Jetonomy\Models\Post::update( $id, array( 'status' => $status ) );
			return true;
		}

		if ( ! \Jetonomy\Models\Reply::find( $id ) ) {
			return $this->not_found( 'Reply' );
		}
		\Jetonomy\Models\Reply::update( $id, array( 'status' => $status ) );
		return true;
	}

	/**
	 * Approve a single post or reply (shared by single-item POST and bulk REST routes).
	 *
	 * Idempotent: if the item is already published the helper still fires the
	 * `jetonomy_content_moderated` action with the moderator's ID so audit
	 * trails record the explicit approval click.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Row ID.
	 * @return bool|WP_Error True on success, WP_Error if the row is missing.
	 */
	private function approve_item( string $type, int $id ): bool|WP_Error {
		$result = $this->set_status( $type, $id, 'publish' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action( 'jetonomy_content_moderated', 'approved', $type, $id, get_current_user_id() );

		return true;
	}

	/**
	 * Mark a single post or reply as spam (shared by single-item POST and bulk REST routes).
	 *
	 * Applies the -20 reputation penalty to the author and fires the
	 * `jetonomy_content_moderated` action so any future per-item business rule
	 * (notify-reporter, AI feedback loop) executes identically across both
	 * dispatch paths.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Row ID.
	 * @return bool|WP_Error True on success, WP_Error if the row is missing.
	 */
	private function spam_item( string $type, int $id ): bool|WP_Error {
		global $wpdb;
		$table = table( 'post' === $type ? 'posts' : 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		if ( ! $row ) {
			return $this->not_found( 'post' === $type ? 'Post' : 'Reply' );
		}

		$result = $this->set_status( $type, $id, 'spam' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$author_id = (int) $row->author_id;
		if ( $author_id ) {
			Reputation::award( $author_id, 'post_removed' );
		}

		do_action( 'jetonomy_content_moderated', 'spam', $type, $id, get_current_user_id() );

		return true;
	}

	/**
	 * Soft-delete a single post or reply (shared by single-item POST and bulk REST routes).
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Row ID.
	 * @return bool|WP_Error True on success, WP_Error if the row is missing.
	 */
	private function trash_item( string $type, int $id ): bool|WP_Error {
		$result = $this->set_status( $type, $id, 'trash' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action( 'jetonomy_content_moderated', 'trash', $type, $id, get_current_user_id() );

		return true;
	}
}
