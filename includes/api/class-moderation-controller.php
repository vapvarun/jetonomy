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
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Moderation\Moderation_Service;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
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

		// List flags, optionally filtered by status.
		register_rest_route(
			$ns,
			'/moderation/flags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_flags' ],
				'permission_callback' => [ $this, 'require_moderate' ],
				'args'                => [
					// Was undeclared, so the route ALWAYS returned pending flags and
					// any "resolved" / "all" filter a client offered was dead UI.
					// Statuses are the ones Flag actually writes: a new flag is
					// 'pending'; resolving it sets 'valid' or 'dismissed'.
					'status' => [
						'type'    => 'string',
						'default' => 'pending',
						'enum'    => [ 'pending', 'valid', 'dismissed', 'all' ],
					],
				],
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

		// List active restrictions (the moderator ban-management surface). This is
		// a SECOND endpoint on the same /moderation/ban path — register_rest_route
		// with override=false appends it to the POST endpoint above rather than
		// clobbering it, so GET (list) and POST (ban) coexist on one route.
		register_rest_route(
			$ns,
			'/moderation/ban',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_bans' ],
				'permission_callback' => [ $this, 'require_moderate' ],
				'args'                => [
					'type'     => [
						'type' => 'string',
						'enum' => [ 'global_ban', 'space_ban', 'silence' ],
					],
					'user_id'  => [ 'type' => 'integer' ],
					'space_id' => [ 'type' => 'integer' ],
					'limit'    => [ 'type' => 'integer' ],
					'offset'   => [ 'type' => 'integer' ],
				],
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
		$status_filter = (string) $request->get_param( 'status' );
		$type_filter   = (string) $request->get_param( 'type' );

		// Map the status filter to the set of moderation statuses. Data access
		// goes through the Post/Reply models (shared with the wp-admin queue) —
		// no raw SQL here — and is paginated so a 100k-item queue never loads
		// the whole set into memory. Served by the status_created index.
		$statuses = 'all' === $status_filter || '' === $status_filter
			? array( 'pending', 'spam' )
			: array( $status_filter );

		$pagination = $this->get_pagination( $request );
		$want_posts = '' === $type_filter || 'post' === $type_filter;
		$want_reply = '' === $type_filter || 'reply' === $type_filter;

		$post_total  = $want_posts ? Post::count_by_status( $statuses ) : 0;
		$reply_total = $want_reply ? Reply::count_by_status( $statuses ) : 0;
		$total       = $post_total + $reply_total;

		// Fetch a bounded window from each table, tag the object type, merge and
		// sort by created_at DESC, then trim to the page size. Over-fetching each
		// side by (offset + limit) is what lets a single interleaved page be
		// assembled correctly; both slices are still index-served and bounded.
		$window = $pagination['offset'] + $pagination['limit'];

		$posts = $want_posts ? Post::list_by_status( $statuses, $window, 0 ) : array();
		foreach ( $posts as $row ) {
			$row->object_type = 'post';
		}
		$replies = $want_reply ? Reply::list_by_status( $statuses, $window, 0 ) : array();
		foreach ( $replies as $row ) {
			$row->object_type = 'reply';
		}

		$merged = array_merge( $posts, $replies );
		usort(
			$merged,
			static function ( $a, $b ) {
				return strcmp( (string) $b->created_at, (string) $a->created_at );
			}
		);
		$page = array_slice( $merged, $pagination['offset'], $pagination['limit'] );

		// Name the author. The raw rows carry only author_id, so every client was
		// rendering "post by unknown" — a moderation queue that cannot tell you who
		// wrote the thing you are about to approve, spam or trash is close to
		// useless, and the decision often turns on exactly that. Batch-loaded via
		// the shared helper: one query for the page, never a get_userdata() per row.
		$page = $this->enrich_with_author( $page );

		$response = $this->paginated_response(
			$page,
			array(
				'total'  => $total,
				'offset' => $pagination['offset'],
			)
		);
		$response->header( 'X-Jetonomy-Pending-Flags', (string) Flag::count_pending() );

		return $response;
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
		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];
		$status     = (string) ( $request->get_param( 'status' ) ?: 'pending' );

		$flags = 'all' === $status
			? Flag::list_all( $limit, $offset )
			: Flag::list_by_status( $status, $limit, $offset );

		// Shared with the per-space queue — see Base_Controller::enrich_flag_actors().
		// This used to be written out inline here, which is precisely why the
		// per-space screen still said "Reported by unknown" after this one was fixed.
		$flags = $this->enrich_flag_actors( $flags );

		// Count for the SAME status we listed, or has_more lies on every filter
		// other than 'pending'.
		$total = Flag::count_by_status( $status );

		return $this->paginated_response(
			$flags,
			[
				'total'  => $total,
				'offset' => $offset,
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
	 *
	 * Delegates to Moderation_Service::resolve_flag — the single owner of the
	 * resolution contract (content trash on valid, related-flag clearing,
	 * reporter reputation award, jetonomy_flag_resolved hook). This route
	 * previously called Flag::resolve() directly, so flags resolved through
	 * the global REST surface skipped all of those side effects while the
	 * admin-AJAX, CLI, and space-moderation surfaces applied them.
	 */
	public function resolve_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );

		$flag = Flag::find( $id );
		if ( ! $flag ) {
			return $this->not_found( 'Flag' );
		}

		$resolved = Moderation_Service::resolve_flag( get_current_user_id(), $id, $status );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
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

		// Who may be TARGETED. The cap matrix above only ever checked the actor,
		// so any user with jetonomy_moderate (the editor role, by default) could
		// issue a global_ban against the site administrator — and a global ban makes
		// REST_Auth reject that account's mutations, so a moderator could lock the
		// owner out of their own community. Verified against a live site before this
		// guard existed: an editor banned user 1 and the row was written.
		//
		// Rules, mirroring the ones user-blocking already enforces:
		// - nobody restricts themselves,
		// - nobody restricts an administrator over REST,
		// - a moderator cannot restrict another moderator; only an admin can.
		if ( $user_id === $actor_id ) {
			return new WP_Error(
				'jetonomy_cannot_ban_self',
				__( 'You cannot restrict your own account.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'jetonomy_cannot_ban_admin',
				__( 'Administrators cannot be restricted.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		if ( user_can( $user_id, 'jetonomy_moderate' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'jetonomy_cannot_ban_moderator',
				__( 'Only an administrator can restrict a moderator.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
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
	 * GET /moderation/ban — list active member restrictions for the moderator
	 * ban-management surface (the app's "Banned members" screen).
	 *
	 * Gated on jetonomy_moderate (require_moderate). Paginated + filterable by
	 * type / user_id / space_id. Each row is enriched with the banned member,
	 * the issuing moderator, and the space (for space bans), plus UTC-ISO
	 * timestamps so the app formats expiry/issued time in the site timezone.
	 */
	public function list_bans( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$pagination = $this->get_pagination( $request );

		$filters = [
			'limit'  => $pagination['limit'],
			'offset' => $pagination['offset'],
		];

		$type = (string) $request->get_param( 'type' );
		if ( '' !== $type ) {
			$filters['type'] = $type;
		}
		$user_id = absint( $request->get_param( 'user_id' ) );
		if ( $user_id ) {
			$filters['user_id'] = $user_id;
		}
		$space_id = $request->get_param( 'space_id' ) ? absint( $request->get_param( 'space_id' ) ) : null;
		if ( $space_id ) {
			$filters['space_id'] = $space_id;
		}

		$rows  = Restriction::list_active( $filters );
		$total = Restriction::count_active( $filters );

		// Batch-load banned members AND issuing moderators in one query, plus the
		// spaces referenced by space bans — no per-row get_userdata()/Space::find().
		$user_ids  = [];
		$space_ids = [];
		foreach ( $rows as $row ) {
			$user_ids[] = (int) $row->user_id;
			if ( (int) $row->issued_by ) {
				$user_ids[] = (int) $row->issued_by;
			}
			if ( $row->space_id ) {
				$space_ids[] = (int) $row->space_id;
			}
		}
		$users  = $this->batch_load_users( array_values( array_unique( array_filter( $user_ids ) ) ) );
		$spaces = [];
		foreach ( array_unique( array_filter( $space_ids ) ) as $sid ) {
			$space = Space::find( (int) $sid );
			if ( $space ) {
				$spaces[ (int) $sid ] = $space;
			}
		}

		$items = [];
		foreach ( $rows as $row ) {
			$uid    = (int) $row->user_id;
			$banned = $users[ $uid ] ?? null;
			$issuer = isset( $users[ (int) $row->issued_by ] ) ? $users[ (int) $row->issued_by ] : null;
			$space  = ( $row->space_id && isset( $spaces[ (int) $row->space_id ] ) ) ? $spaces[ (int) $row->space_id ] : null;

			$items[] = [
				'id'             => (int) $row->id,
				'user_id'        => $uid,
				'user'           => [
					'id'           => $uid,
					'display_name' => $banned ? $banned->display_name : __( '[deleted]', 'jetonomy' ),
					'user_login'   => $banned ? $banned->user_login : '',
					'avatar_url'   => $banned ? \Jetonomy\Avatar::display_url( $uid, 64 ) : '',
				],
				'type'           => (string) $row->type,
				'space_id'       => $row->space_id ? (int) $row->space_id : null,
				'space_title'    => $space ? $space->title : null,
				'reason'         => null !== $row->reason ? (string) $row->reason : null,
				'issued_by'      => (int) $row->issued_by,
				'issuer_name'    => $issuer ? $issuer->display_name : ( (int) $row->issued_by ? __( '[deleted]', 'jetonomy' ) : __( 'System', 'jetonomy' ) ),
				'expires_at'     => $row->expires_at ?: null,
				'expires_at_gmt' => \Jetonomy\to_iso8601_z( $row->expires_at ?? null ),
				'created_at'     => $row->created_at,
				'created_at_gmt' => \Jetonomy\to_iso8601_z( $row->created_at ?? null ),
			];
		}

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $pagination['offset'],
			]
		);
	}

	/**
	 * Approve a single post or reply (shared by single-item POST and bulk REST routes).
	 *
	 * Delegates to the Moderation_Service choke-point, which owns the status
	 * write, pending-flag resolution, reputation, and the canonical
	 * `jetonomy_content_moderated` ('approve') action — so REST, admin AJAX,
	 * abilities, and space-mod all behave identically. Idempotent.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Row ID.
	 * @return bool|WP_Error True on success, WP_Error if the row is missing.
	 */
	private function approve_item( string $type, int $id ): bool|WP_Error {
		return Moderation_Service::set_object_status( get_current_user_id(), $type, $id, 'approve' );
	}

	/**
	 * Mark a single post or reply as spam (shared by single-item POST and bulk REST routes).
	 *
	 * Delegates to the Moderation_Service choke-point (status write, flag
	 * resolution, author reputation penalty, and the `jetonomy_content_moderated`
	 * 'spam' action).
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Row ID.
	 * @return bool|WP_Error True on success, WP_Error if the row is missing.
	 */
	private function spam_item( string $type, int $id ): bool|WP_Error {
		return Moderation_Service::set_object_status( get_current_user_id(), $type, $id, 'spam' );
	}

	/**
	 * Soft-delete a single post or reply (shared by single-item POST and bulk REST routes).
	 *
	 * Delegates to the Moderation_Service choke-point (status write, flag
	 * resolution, and the `jetonomy_content_moderated` 'trash' action).
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Row ID.
	 * @return bool|WP_Error True on success, WP_Error if the row is missing.
	 */
	private function trash_item( string $type, int $id ): bool|WP_Error {
		return Moderation_Service::set_object_status( get_current_user_id(), $type, $id, 'trash' );
	}
}
