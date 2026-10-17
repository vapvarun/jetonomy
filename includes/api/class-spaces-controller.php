<?php
/**
 * Spaces REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Category;
use Jetonomy\Models\AccessRule;

class Spaces_Controller extends Base_Controller {

	protected $rest_base = 'spaces';

	/**
	 * Valid member roles.
	 */
	// Single source of truth lives on the model so REST, admin, and set_role()
	// validate against one list.
	private const VALID_ROLES = SpaceMember::VALID_ROLES;

	/**
	 * Valid join policies.
	 */
	private const VALID_JOIN_POLICIES = array( 'open', 'approval', 'invite' );

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Collection routes.
		register_rest_route(
			$ns,
			'/spaces',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
					'args'                => $this->get_list_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					// REST_Auth handles login + nonce. The detailed cap matrix
					// (manage_options OR admin-allowed role) stays in
					// create_permission_check because it consults
					// the admin's saved frontend_space_creation_roles list and
					// the user's WP roles — neither belongs in REST_Auth.
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_create_args(),
				),
			)
		);

		// Single space routes.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					// Space-admin check happens inside the handler because it
					// resolves the route's `id` against per-space role rows.
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_update_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => array(
						'mode' => array(
							'type'     => 'string',
							'required' => false,
							'enum'     => array( 'transfer', 'purge' ),
							'default'  => 'transfer',
						),
					),
				),
			)
		);

		// Member collection routes.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/members',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_members' ),
					'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'join_space' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
			)
		);

		// Privileged members read route — powers the "Managed by" sidebar
		// card in the front-end (1.4.0 G1) and is also the canonical surface
		// for the future mobile/native client and any third-party integrator
		// that wants to ask "who runs this space?" without scraping the
		// sidebar. Public read so the answer matches what an anonymous
		// visitor sees on the page.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/privileged-members',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_privileged_members' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		// Join-request moderation — list pending (privileged read) plus
		// approve / deny actions. Mirrors the wp-admin Join Requests tab so a
		// space moderator who is NOT a WP admin can manage requests from the
		// community front-end. Approve/deny reuse the same JoinRequest model +
		// SpaceMember::add path as the admin AJAX handler, so the
		// jetonomy_join_request_approved/denied notifications (and requester
		// email) fire identically on every surface.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/join-requests',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_join_requests' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => $this->get_collection_params(),
			)
		);
		// A member takes back their own pending request (only ever their own).
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/join-requests/mine',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'withdraw_join_request' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/join-requests/(?P<request_id>\d+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve_join_request' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/join-requests/(?P<request_id>\d+)/deny',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deny_join_request' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);

		// Invite link routes.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/invite',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_invite' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				'args'                => array(
					'max_uses'   => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 0,
					),
					'expires_at' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		// List + revoke. Until 1.8.0 these existed ONLY as admin-ajax handlers
		// (Admin\Ajax\Spaces_Handler), so the front-end had no way to show or
		// withdraw the links it could already generate. A customer-facing
		// surface must not call admin-ajax (CLAUDE.md: "Frontend REST-only,
		// backend AJAX is acceptable"), so the operations get REST routes here
		// and the admin handlers are left exactly as they are.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/invites',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_invites' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/invites/(?P<invite_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'revoke_invite' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);

		register_rest_route(
			$ns,
			'/invite/(?P<token>[a-zA-Z0-9]+)',
			array(
				// POST: accepting changes membership, so it must never run on a
				// plain GET (link prefetchers, cross-site image tags).
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'use_invite' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);

		// Individual member routes.
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/members/(?P<user_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'leave_space' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_member_role' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => array(
						'role' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => self::VALID_ROLES,
						),
					),
				),
			)
		);

		/*
		 * Access rules. jt_access_rules is the table that decides who may enter a
		 * space, and until 1.9.4 it had ZERO REST coverage - configurable only
		 * from wp-admin, so no API-driven or app-based space-admin flow could
		 * touch it. Capability matches the AJAX handler (jetonomy_manage_spaces).
		 */
		register_rest_route(
			$ns,
			'/spaces/(?P<id>\d+)/access-rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_access_rules' ),
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_spaces' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_access_rule' ),
					'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_spaces' ),
					'args'                => array(
						'rule_type'  => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'membership', 'role', 'capability', 'trust_level', 'logged_in', 'everyone' ),
						),
						'rule_value' => array( 'type' => 'string' ),
						'grants'     => array(
							'type'    => 'string',
							'default' => 'read',
							'enum'    => array( 'read', 'participate', 'full' ),
						),
						'space_role' => array(
							'type'    => 'string',
							'default' => 'viewer',
							'enum'    => array( 'viewer', 'member', 'moderator', 'admin' ),
						),
						'priority'   => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/access-rules/(?P<rule_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_access_rule' ),
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_spaces' ),
			)
		);
	}

	/**
	 * GET /spaces/{id}/access-rules
	 */
	public function list_access_rules( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		if ( ! Space::find( $space_id ) ) {
			return new WP_Error( 'jetonomy_space_not_found', sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'data' => AccessRule::list_for_space( $space_id ) ), 200 );
	}

	/**
	 * POST /spaces/{id}/access-rules
	 *
	 * Shares AccessRule::enforce_gate_on_public_space() with the wp-admin AJAX
	 * path. A restrictive rule on a PUBLIC space silently does nothing, because
	 * public spaces are always readable - so the space is switched to Private and
	 * the response says so. Without that shared call this route would have
	 * recreated the "configured but content still accessible" bug
	 * on a second surface.
	 */
	public function create_access_rule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		if ( ! Space::find( $space_id ) ) {
			return new WP_Error( 'jetonomy_space_not_found', sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ), array( 'status' => 404 ) );
		}

		$rule_type  = (string) $request->get_param( 'rule_type' );
		$rule_value = sanitize_text_field( (string) $request->get_param( 'rule_value' ) );
		$invalid    = AccessRule::value_error( $rule_type, $rule_value );
		if ( $invalid ) {
			return $invalid;
		}

		$id = AccessRule::create(
			array(
				'space_id'   => $space_id,
				'rule_type'  => $rule_type,
				'rule_value' => '' !== $rule_value ? $rule_value : null,
				'grants'     => (string) $request->get_param( 'grants' ),
				'space_role' => (string) $request->get_param( 'space_role' ),
				'priority'   => (int) $request->get_param( 'priority' ),
			)
		);

		if ( ! $id ) {
			return new WP_Error( 'jetonomy_rule_create_failed', __( 'Failed to create access rule.', 'jetonomy' ), array( 'status' => 500 ) );
		}

		$made_private = AccessRule::enforce_gate_on_public_space( $space_id, $rule_type );

		return new WP_REST_Response(
			array(
				'data'         => AccessRule::find( (int) $id ),
				'made_private' => $made_private,
				'message'      => $made_private
					? sprintf( /* translators: %s: space label, lowercase (e.g. "space"). */ __( 'Access rule added. This %1$s was switched to Private so the rule can restrict access. A rule cannot gate a public %1$s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) )
					: __( 'Access rule added.', 'jetonomy' ),
			),
			201
		);
	}

	/**
	 * DELETE /access-rules/{rule_id}
	 */
	public function delete_access_rule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rule_id = (int) $request->get_param( 'rule_id' );

		if ( ! AccessRule::find( $rule_id ) ) {
			return new WP_Error( 'jetonomy_rule_not_found', __( 'Access rule not found.', 'jetonomy' ), array( 'status' => 404 ) );
		}

		$deleted = AccessRule::delete( $rule_id );
		if ( is_wp_error( $deleted ) || ! $deleted ) {
			return new WP_Error( 'jetonomy_rule_delete_failed', __( 'Failed to delete access rule.', 'jetonomy' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $rule_id,
			),
			200
		);
	}

	/**
	 * Permission check: site admins always qualify; beyond that the admin opts
	 * specific WP roles into the front-end create flow via Settings → General.
	 * Resolved by Capabilities::can_create_space_frontend() so this route, the
	 * /new-space/ form, and the user-panel link cannot disagree.
	 *
	 * Trust-level fallback was considered and dropped — admin should pick
	 * the roles they trust to create spaces explicitly, not have community
	 * activity grant the right.
	 */
	public function create_permission_check(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'jetonomy_unauthorized',
				__( 'You must be logged in.', 'jetonomy' ),
				array( 'status' => 401 )
			);
		}
		if ( ! \Jetonomy\Permissions\Capabilities::can_create_space_frontend() ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * GET /spaces — List spaces with optional filters.
	 *
	 * Visibility filtering is handled entirely in SQL via Space::list_visible()
	 * so that pagination totals are accurate and there is no N+1 membership check.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$user_id        = get_current_user_id();
		$category_id    = $request->get_param( 'category_id' ) ? absint( $request->get_param( 'category_id' ) ) : null;
		$type           = $request->get_param( 'type' ) ? sanitize_text_field( $request->get_param( 'type' ) ) : null;
		$visibility     = $request->get_param( 'visibility' ) ? sanitize_text_field( $request->get_param( 'visibility' ) ) : null;
		$postable_by_me = (bool) $request->get_param( 'postable_by_me' );
		$movable_by_me  = (bool) $request->get_param( 'movable_by_me' );
		$pagination     = $this->get_pagination( $request );

		if ( $postable_by_me ) {
			// Member-scoped picker: start from the user's space_member rows so
			// the result isn't truncated by the global sort_order ASC, title ASC
			// pagination window. Earlier shape widened list_visible to LIMIT 200,
			// which silently dropped member spaces ranked > 200 across the whole
			// community — the composer would then hide spaces the user can post
			// in. Hydrating the joined rows directly is O(memberships per user),
			// which is bounded.
			if ( ! $user_id ) {
				$spaces = array();
				$total  = 0;
			} else {
				$member_rows = \Jetonomy\Models\SpaceMember::list_user_spaces( $user_id );
				$member_ids  = array_map( static fn ( $r ) => (int) $r->space_id, $member_rows );

				$spaces = Space::list_by_ids( $member_ids );
				$spaces = array_values(
					array_filter(
						$spaces,
						static function ( $space ) use ( $user_id, $category_id, $type, $visibility ): bool {
							if ( null !== $category_id && (int) $space->category_id !== $category_id ) {
								return false;
							}
							if ( null !== $type && $space->type !== $type ) {
								return false;
							}
							if ( null !== $visibility && $space->visibility !== $visibility ) {
								return false;
							}
							return \Jetonomy\Permissions\Permission_Engine::can( $user_id, 'create_posts', (int) $space->id );
						}
					)
				);
				$total  = count( $spaces );
			}
		} else {
			$result = Space::list_visible(
				$user_id,
				$category_id,
				$type,
				$visibility,
				$pagination['limit'],
				$pagination['offset'],
				'sort_order ASC, title ASC'
			);

			$spaces = $result['spaces'];
			$total  = $result['total'];

			// Target-space filter for the move-topic picker. POST /posts/{id}/move
			// requires move_posts in BOTH source and target, but the picker used
			// to list every visible space - so it offered targets the move would
			// then refuse. Filtering here, not in JS,
			// because doing it client-side would ship the names of spaces the
			// viewer cannot move into.
			//
			// Applied to the returned page, so `total` remains the count of
			// VISIBLE spaces matching the query, not of movable ones. The picker
			// is search-driven and does not page, so that is the honest cheap
			// answer; a movable-aware COUNT would need the permission engine in
			// SQL, which it is not.
			if ( $movable_by_me && $user_id ) {
				$spaces = array_values(
					array_filter(
						$spaces,
						static fn ( $space ): bool => \Jetonomy\Permissions\Permission_Engine::can( $user_id, 'move_posts', (int) $space->id )
					)
				);
			} elseif ( $movable_by_me ) {
				$spaces = array();
			}
		}

		// Batch the viewer flags before serializing:
		// prepare_space() reads is_member/viewer_role (get_role memo) and
		// is_subscribed (subscription memo) per row — 30 spaces cost 90 point
		// queries before these two warms; now 2 IN-queries for the page.
		if ( $user_id ) {
			$space_ids = array_map( static fn( $s ) => (int) $s->id, $spaces );
			SpaceMember::warm_viewer_roles( $user_id, $space_ids );
			\Jetonomy\Models\Subscription::warm_viewer_subscriptions( $user_id, 'space', $space_ids );
		}

		$items       = array_map( array( $this, 'prepare_space' ), $spaces );
		$total_pages = (int) ceil( $total / max( 1, $pagination['limit'] ) );

		$meta = array(
			'total'  => $total,
			'offset' => $pagination['offset'],
		);
		if ( $postable_by_me ) {
			// Why the composer's picker is empty for a banned or silenced member.
			$restricted          = \Jetonomy\Permissions\Permission_Engine::restriction_error( $user_id, 'create_posts' );
			$meta['restriction'] = $restricted
				? array(
					'code'    => $restricted->get_error_code(),
					'message' => $restricted->get_error_message(),
				)
				: null;
		}

		$response = $this->paginated_response( $items, $meta );

		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET /spaces/{id} — Get a single space.
	 */
	public function get_item( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$space = $this->find_unconcealed_space( $id );
		if ( is_wp_error( $space ) ) {
			return $space;
		}

		$user_id = get_current_user_id();

		// Admission, not the roster. Asking SpaceMember alone refused a 403 to
		// exactly the learners a course's access rule exists to admit - the web
		// page let them in and the API did not, so the app could not show a room
		// the browser could.
		if ( ! Space::readable_by_viewer( $space, (int) $user_id ) ) {
			return $this->permission_error();
		}

		return new WP_REST_Response( $this->prepare_space( $space ), 200 );
	}

	/**
	 * POST /spaces — Create a new space.
	 *
	 * REST_Auth at the route layer enforces login + nonce. The cap matrix
	 * (manage_options / admin-allowed role) is too
	 * contextual for the helper, so re-run create_permission_check() here.
	 */
	public function create_item( $request ) {
		$gate = $this->create_permission_check();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$title = sanitize_text_field( $request->get_param( 'title' ) );

		if ( empty( $title ) ) {
			return $this->validation_error( sprintf( /* translators: %s: the singular Space label. */ __( '%s title is required.', 'jetonomy' ), \Jetonomy\space_label() ) );
		}

		$slug = $request->get_param( 'slug' )
			? sanitize_title( $request->get_param( 'slug' ) )
			: sanitize_title( $title );

		$slug = $this->unique_slug( $slug );

		// Handle settings: accept array or JSON string. Normalized through the
		// model (space id 0 — nothing to merge into yet) so a space created
		// with `posts_per_page: ""` doesn't start life with the phantom value
		// the update path now rejects.
		$settings_raw = $request->get_param( 'settings' );
		$settings     = '';
		if ( $settings_raw ) {
			$incoming = is_array( $settings_raw ) ? $settings_raw : json_decode( (string) $settings_raw, true );
			if ( is_array( $incoming ) ) {
				$settings = wp_json_encode( Space::merge_settings( 0, $incoming ) );
			}
		}

		// When the client doesn't supply a type, fall back to the admin-configured default
		// so Settings → Default Space Type actually controls new-space creation.
		// A supplied but unknown type is refused (args enum + Space::create()),
		// never silently swapped for the default.
		$requested_type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' === $requested_type ) {
			$jt_settings    = get_option( 'jetonomy_settings', array() );
			$configured     = sanitize_key( (string) ( $jt_settings['default_space_type'] ?? 'forum' ) );
			$requested_type = in_array( $configured, Space::valid_types(), true ) ? $configured : 'forum';
		}

		$visibility  = sanitize_text_field( (string) $request->get_param( 'visibility' ) ) ?: 'public';
		$join_policy = sanitize_text_field( (string) $request->get_param( 'join_policy' ) ) ?: 'open';

		$combo = Space::validate_visibility_join_policy( $visibility, $join_policy );
		if ( is_wp_error( $combo ) ) {
			return $combo;
		}

		$cover = esc_url_raw( (string) $request->get_param( 'cover_image' ) );
		if ( ! \Jetonomy\Permissions\Capabilities::can_set_image( $cover, '', get_current_user_id() ) ) {
			return new WP_Error( 'jetonomy_forbidden', __( 'You do not have permission to upload images.', 'jetonomy' ), array( 'status' => 403 ) );
		}

		$data = array(
			'category_id' => absint( $request->get_param( 'category_id' ) ) ?: null,
			'title'       => $title,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'type'        => $requested_type,
			'visibility'  => $visibility,
			'join_policy' => $join_policy,
			'icon'        => sanitize_text_field( (string) $request->get_param( 'icon' ) ),
			'cover_image' => $cover,
			'settings'    => $settings,
			'author_id'   => get_current_user_id(),
			// Only carried when the client actually sent it. Left null it is
			// stripped below, and Space::create() assigns MAX(sort_order)+1 for
			// the category so new spaces append. Note this is NOT the same as
			// the categories controller, which passes absint() unconditionally:
			// Category::create() defaults to 0, so writing 0 there matches the
			// model. Doing that here would pin every API-created space to the
			// top of its category and lose the append behaviour.
			'sort_order'  => null !== $request->get_param( 'sort_order' )
				? absint( $request->get_param( 'sort_order' ) )
				: null,
		);

		// Remove empty optional fields so DB defaults apply. absint() of an
		// explicit 0 survives this - the callback rejects only null and ''.
		$data = array_filter( $data, fn( $v ) => null !== $v && '' !== $v );

		$id = Space::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( ! $id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Failed to create %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				array( 'status' => 500 )
			);
		}

		// Space::create() made the creator its admin. If a join filter vetoed
		// that, nobody could manage the space: remove it rather than leave an
		// orphan behind a "failed" answer that invites a duplicate retry.
		if ( 'admin' !== SpaceMember::get_role( (int) $id, get_current_user_id() ) ) {
			\Jetonomy\Space_Purge::purge( (int) $id );
			return new WP_Error( 'jetonomy_create_failed', sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Failed to create %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 500 ) );
		}

		// The creator follows their space, so they hear about new topics; they
		// can unfollow like anyone else.
		Subscription::subscribe( get_current_user_id(), 'space', (int) $id );

		$space = Space::find( $id );

		/**
		 * Fires after a space is created. Lets extensions persist data tied to
		 * the new space (e.g. Pro custom fields bundle a `custom_fields` map
		 * into the create request, mirroring posts).
		 *
		 * @since 1.5.0
		 * @param int             $id      The new space ID.
		 * @param WP_REST_Request $request The create request.
		 */
		do_action( 'jetonomy_after_create_space', $id, $request );

		return new WP_REST_Response( $this->prepare_space( $space ), 201 );
	}

	/**
	 * PATCH /spaces/{id} — Partially update a space (space admin or manage_options).
	 */
	public function update_item( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		if ( ! $this->is_space_admin( $id, $user_id ) ) {
			return $this->permission_error();
		}

		// An archived or locked space is read-only to its own admins: a site
		// admin's archive, or a purge in progress, must not be edited around.
		// The one change allowed is unarchiving (status=active, checked by
		// can_unarchive() below). Site staff who manage spaces can still edit.
		if ( in_array( (string) ( $space->status ?? 'active' ), array( 'archived', 'locked' ), true )
			&& ! current_user_can( 'jetonomy_manage_spaces' )
			&& 'active' !== $request->get_param( 'status' ) ) {
			return new WP_Error( 'jetonomy_space_readonly', sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'This %s is archived or locked, so it cannot be edited. Unarchive it first.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 409 ) );
		}

		$data = array();

		if ( null !== $request->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'slug' ) ) {
			$data['slug'] = sanitize_title( $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}
		if ( null !== $request->get_param( 'category_id' ) ) {
			$new_category_id = absint( $request->get_param( 'category_id' ) ) ?: null;
			$old_category_id = (int) ( $space->category_id ?? 0 );

			// A request that clears the category is only honoured when the
			// editor can actually SEE the category being cleared. Otherwise it
			// is the space-edit form submitting 0 because the current option
			// was missing from a visibility-filtered dropdown, and accepting it
			// unfiles the space - moving a public space out of a hidden
			// category and onto the community home. The template always
			// renders the option; this is the half a crafted request cannot
			// get around.
			$clearing = null === $new_category_id && $old_category_id > 0;
			if ( $clearing && null === \Jetonomy\Models\Category::find_visible( $old_category_id ) ) {
				$new_category_id = $old_category_id;
			}

			$data['category_id'] = $new_category_id;
		}
		if ( null !== $request->get_param( 'type' ) ) {
			$data['type'] = sanitize_text_field( $request->get_param( 'type' ) );
		}
		if ( null !== $request->get_param( 'visibility' ) ) {
			$data['visibility'] = sanitize_text_field( $request->get_param( 'visibility' ) );
		}
		if ( null !== $request->get_param( 'join_policy' ) ) {
			$data['join_policy'] = sanitize_text_field( $request->get_param( 'join_policy' ) );
		}

		// Unarchive only: archiving goes through the hand-over (DELETE), and a
		// locked space or an archive a site admin imposed stays as it is.
		if ( null !== $request->get_param( 'status' ) ) {
			if ( 'active' !== $request->get_param( 'status' ) ) {
				return new WP_Error( 'jetonomy_invalid_status', sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'This %s can only be set back to active here.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 400 ) );
			}
			if ( ! Space::can_unarchive( $space, $user_id ) ) {
				return $this->permission_error();
			}
			$data['status'] = 'active';
		}

		// Cross-validate visibility + join_policy after overlaying the patch
		// onto the existing space so partial PATCHes (just visibility, or
		// just join_policy) still trip the rule. Hidden + non-invite is
		// rejected with a 400 here; the admin form's JS prevents the user
		// from getting this far in normal use.
		if ( isset( $data['visibility'] ) || isset( $data['join_policy'] ) ) {
			$effective_visibility  = $data['visibility'] ?? ( $space->visibility ?? 'public' );
			$effective_join_policy = $data['join_policy'] ?? ( $space->join_policy ?? 'open' );
			$combo                 = Space::validate_visibility_join_policy(
				(string) $effective_visibility,
				(string) $effective_join_policy
			);
			if ( is_wp_error( $combo ) ) {
				return $combo;
			}
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$data['sort_order'] = absint( $request->get_param( 'sort_order' ) );
		}
		if ( null !== $request->get_param( 'icon' ) ) {
			$data['icon'] = sanitize_text_field( $request->get_param( 'icon' ) );
		}
		if ( null !== $request->get_param( 'cover_image' ) ) {
			$data['cover_image'] = esc_url_raw( (string) $request->get_param( 'cover_image' ) );
			if ( ! \Jetonomy\Permissions\Capabilities::can_set_image( $data['cover_image'], (string) ( $space->cover_image ?? '' ), get_current_user_id() ) ) {
				return new WP_Error( 'jetonomy_forbidden', __( 'You do not have permission to upload images.', 'jetonomy' ), array( 'status' => 403 ) );
			}
		}
		if ( null !== $request->get_param( 'settings' ) ) {
			$settings_raw = $request->get_param( 'settings' );
			if ( is_array( $settings_raw ) ) {
				$incoming = $settings_raw;
			} else {
				$decoded  = json_decode( (string) $settings_raw, true );
				$incoming = is_array( $decoded ) ? $decoded : null;
			}
			if ( null !== $incoming ) {
				// Merge + normalize through the model, same as the admin AJAX
				// writer: a raw array_merge() would store the front-end edit form's
				// `posts_per_page: ""` (its "use the default" value) verbatim and read it back as a
				// limit of 1.
				$data['settings'] = wp_json_encode( Space::merge_settings( $id, $incoming ) );
			} elseif ( '' === $settings_raw ) {
				// Explicit empty string means reset; stored as empty JSON object.
				$data['settings'] = '';
			}
		}

		if ( empty( $data ) ) {
			return $this->validation_error( __( 'No fields provided for update.', 'jetonomy' ) );
		}

		$data['updated_at'] = \Jetonomy\now();
		$saved              = Space::update( $id, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$updated = Space::find( $id );

		/**
		 * Fires after a space is updated. Mirror of jetonomy_after_update_post:
		 * receives the updated space object plus a context array carrying the
		 * request so extensions (e.g. Pro custom fields) can persist edits.
		 *
		 * @since 1.5.0
		 * @param object                                     $updated Space object.
		 * @param array{user_id:int,request:WP_REST_Request} $context Context.
		 */
		do_action(
			'jetonomy_after_update_space',
			$updated,
			array(
				'user_id' => get_current_user_id(),
				'request' => $request,
			)
		);

		return new WP_REST_Response( $this->prepare_space( $updated ), 200 );
	}

	/**
	 * DELETE /spaces/{id} — Delete a space (space admin or manage_options).
	 */
	public function delete_item( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		if ( ! $this->is_space_admin( $id, $user_id ) ) {
			return $this->permission_error();
		}

		// Deleting a space defaults to TRANSFER, never to destroying content.
		// A space contains other members' topics and replies; dropping it
		// destroys their contributions, not just the owner's. This mirrors
		// delete_account(), which already defaults to anonymize-not-destroy for
		// the same reason.
		$mode = (string) ( $request->get_param( 'mode' ) ?: 'transfer' );

		if ( 'purge' === $mode ) {
			// Who may destroy content is a site-owner decision. Space admins can
			// purge only when the owner has allowed it; manage_options always
			// can. Enforced here rather than in the UI - the route accepts the
			// param whatever the form renders.
			$settings      = get_option( 'jetonomy_settings', array() );
			$admins_may    = ! empty( $settings['allow_space_admin_purge'] );
			$is_site_admin = current_user_can( 'manage_options' );

			if ( ! $is_site_admin && ! $admins_may ) {
				return new WP_Error(
					'jetonomy_purge_not_allowed',
					sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'Permanently deleting this %s is restricted to site administrators on this community.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					array( 'status' => 403 )
				);
			}

			// Queued, not inline. A space with 50k topics and their replies
			// cannot be deleted inside one request, and this endpoint is
			// reachable from a browser - the caller must not be holding the
			// connection while it drains.
			//
			// Space_Purge removes children before the space row, so the space
			// stays discoverable until the last batch and an interrupted purge
			// resumes rather than stranding rows.
			$queued = \Jetonomy\Space_Purge::queue( $id );

			// category.space_count is decremented by the purge itself when it
			// reaches the space row, so it is NOT adjusted here - doing both
			// would double-count.
			// "deleted" only when the space row is really gone; a large space
			// is locked and finishes in the background.
			$deleted = null === Space::find( $id );
			return new WP_REST_Response(
				array(
					'deleted' => $deleted,
					'mode'    => 'purge',
					'id'      => $id,
					'queued'  => $queued && ! $deleted,
				),
				$deleted ? 200 : 202
			);
		}

		// Transfer: the space survives, parked, owned by its successor. Only its
		// owner or a site admin may do this, or ownership could bounce between
		// space admins.
		if ( ! Space::can_hand_over( $space, $user_id ) ) {
			return 'archived' === ( $space->status ?? '' )
				? new WP_Error( 'jetonomy_space_archived', sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'This %s is already archived.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 409 ) )
				: $this->permission_error();
		}

		$successor = Space::archive_and_hand_over( $id );
		if ( is_wp_error( $successor ) ) {
			return $successor;
		}

		return new WP_REST_Response(
			array(
				'deleted'   => false,
				'mode'      => 'transfer',
				'id'        => $id,
				'successor' => $successor,
			),
			200
		);
	}

	/**
	 * GET /spaces/{id}/privileged-members — Admins + moderators of a space.
	 *
	 * Powers the "Managed by" sidebar card and any client that shows who runs
	 * a space. Visible on public and private spaces, so non-members know whom
	 * to ask for access; a hidden space answers 404 like the space itself.
	 */
	public function get_privileged_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = $this->find_unconcealed_space( $id );
		if ( is_wp_error( $space ) ) {
			return $space;
		}

		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$members = SpaceMember::list_privileged( $id, $limit );

		$items = array_map(
			static function ( $row ) {
				return array(
					'user_id'      => (int) $row->user_id,
					'role'         => (string) $row->role,
					'display_name' => \Jetonomy\user_display_name( $row ),
					'avatar_url'   => (string) ( $row->avatar_url ?? '' ),
				);
			},
			$members
		);

		return rest_ensure_response( $items );
	}

	/**
	 * A space, or a 404 when this viewer may not know it exists.
	 *
	 * 404 rather than 403 for a hidden space (or one in a hidden category):
	 * a 403 would confirm to a stranger that the space exists, which the
	 * page itself never does.
	 *
	 * @param int $id Space ID.
	 * @return object|WP_Error
	 */
	private function find_unconcealed_space( int $id ) {
		$space = Space::find( $id );
		if ( ! $space || Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
			return $this->not_found( 'Space' );
		}
		return $space;
	}

	/**
	 * GET /spaces/{id}/members — List all members of a space.
	 */
	public function get_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = $this->find_unconcealed_space( $id );
		if ( is_wp_error( $space ) ) {
			return $space;
		}

		$user_id = get_current_user_id();

		// Same admission test as the space itself: someone who may read the space
		// may see who is in it. Gating this on the roster hid the member list
		// from rule-admitted viewers who could already read the space and post
		// in it, which reads as a broken page rather than a permission.
		if ( ! Space::readable_by_viewer( $space, (int) $user_id ) ) {
			return $this->permission_error();
		}

		// Paginate: a large space can have tens of thousands of members, so
		// never load the whole roster. Mirror the space-listing endpoint —
		// COUNT(*) for the real total, then a bounded LIMIT/OFFSET slice.
		// Both are served by the space_members space_role_joined index.
		$pagination = $this->get_pagination( $request );
		$total      = SpaceMember::count_by_space( $id );
		$members    = SpaceMember::list_by_space( $id, $pagination['limit'], $pagination['offset'] );

		// Batch-warm the users before the per-row serializer — prepare_member()
		// does get_userdata + profile + avatar per row, ~3 cold queries x 50
		// rows per roster page. batch_load_users() transitively
		// primes the WP user cache + UserProfile::prime.
		$this->batch_load_users( array_map( static fn( $m ) => (int) $m->user_id, $members ) );

		$items = array_map( array( $this, 'prepare_member' ), $members );

		$response = $this->paginated_response(
			$items,
			array(
				'total'  => $total,
				'offset' => $pagination['offset'],
			)
		);

		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $pagination['limit'] ) ) );

		return $response;
	}

	/**
	 * POST /spaces/{id}/members — Join a space.
	 *
	 * - open:     adds user as member immediately.
	 * - approval: creates a pending join request (stored as a custom option; no JoinRequest model exists yet).
	 * - invite:   not allowed — returns 403.
	 */
	public function join_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = absint( $request->get_param( 'id' ) );
		$message = sanitize_textarea_field( (string) ( $request->get_param( 'message' ) ?? '' ) );

		/*
		 * The policy itself lives in SpaceMember::join(). This method only maps
		 * that decision onto HTTP.
		 *
		 * The facade is the single answer for every caller, so the WP Abilities join-space
		 * path cannot disagree with this one: two copies of a rule is one copy too many.
		 */
		$result = SpaceMember::join( $id, get_current_user_id(), $message );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'pending' === $result['status'] ) {
			return new WP_REST_Response(
				array(
					'status'  => 'pending',
					'message' => empty( $result['already_pending'] )
						? __( 'Join request submitted. Awaiting approval.', 'jetonomy' )
						: sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'You already have a pending join request for this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				),
				202
			);
		}

		return new WP_REST_Response(
			array(
				'status'   => 'joined',
				'space_id' => $id,
				'user_id'  => get_current_user_id(),
				'role'     => 'member',
			),
			201
		);
	}

	/**
	 * DELETE /spaces/{id}/members/{user_id} — Leave a space or remove a member.
	 *
	 * - User can remove themselves.
	 * - Space admin/moderator can remove others.
	 */
	public function leave_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = absint( $request->get_param( 'id' ) );
		$user_id = absint( $request->get_param( 'user_id' ) );
		$space   = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$current_user_id = get_current_user_id();

		$is_self  = $current_user_id === $user_id;
		$is_admin = $this->is_space_admin( $id, $current_user_id );

		if ( ! $is_self && ! $is_admin ) {
			return $this->permission_error();
		}

		if ( ! SpaceMember::is_member( $id, $user_id ) ) {
			return new WP_Error(
				'jetonomy_not_member',
				sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'User does not belong to this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				array( 'status' => 404 )
			);
		}

		$removed = SpaceMember::remove( $id, $user_id, true );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		return new WP_REST_Response(
			array(
				'removed'  => true,
				'space_id' => $id,
				'user_id'  => $user_id,
			),
			200
		);
	}

	/**
	 * GET /spaces/{id}/join-requests — Pending join requests (space mods only).
	 *
	 * Front-end twin of the wp-admin Join Requests tab. Gated to space
	 * moderators/admins (not just WP admins) so space owners can triage from
	 * the community front-end.
	 */
	public function get_join_requests( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );
		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}
		if ( ! \Jetonomy\Permissions\Permission_Engine::is_space_privileged( get_current_user_id(), $id ) ) {
			return $this->permission_error();
		}

		$pagination = $this->get_pagination( $request );
		$rows       = JoinRequest::list_pending_for_space( $id, $pagination['limit'], $pagination['offset'] );
		cache_users( array_map( static fn( $r ) => (int) $r->user_id, $rows ) );

		$items = array();
		foreach ( $rows as $row ) {
			$uid     = (int) $row->user_id;
			$user    = get_userdata( $uid );
			$items[] = array(
				'id'           => (int) $row->id,
				'user_id'      => $uid,
				'display_name' => $user ? \Jetonomy\user_display_name( $user ) : sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Unknown %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', false, true ) ),
				'avatar_url'   => \Jetonomy\Avatar::display_url( $uid, 48 ),
				'profile_url'  => \Jetonomy\get_profile_url( $uid ),
				'message'      => (string) ( $row->message ?? '' ),
				'created_at'   => (string) $row->created_at,
			);
		}

		return $this->paginated_response(
			$items,
			array(
				'total'  => JoinRequest::count_pending_for_space( $id ),
				'offset' => $pagination['offset'],
			)
		);
	}

	/**
	 * DELETE /spaces/{id}/join-requests/mine — Withdraw the caller's pending request.
	 */
	public function withdraw_join_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! JoinRequest::withdraw( $id, get_current_user_id() ) ) {
			return new WP_Error( 'jetonomy_no_pending_request', __( 'You have no pending request here.', 'jetonomy' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'status'   => 'withdrawn',
				'space_id' => $id,
			),
			200
		);
	}

	/**
	 * POST /spaces/{id}/join-requests/{request_id}/approve — Approve + admit.
	 */
	public function approve_join_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		[ $space, $jr, $err ] = $this->resolve_join_request( $request );
		if ( $err ) {
			return $err;
		}

		// Admits the requester, then marks the request approved (which tells
		// them). Same call as the admin AJAX handler and the CLI journey.
		$approved = JoinRequest::approve( (int) $jr->id, get_current_user_id() );
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}

		return new WP_REST_Response(
			array(
				'status'     => 'approved',
				'request_id' => (int) $jr->id,
				'space_id'   => (int) $space->id,
				'user_id'    => (int) $jr->user_id,
			),
			200
		);
	}

	/**
	 * POST /spaces/{id}/join-requests/{request_id}/deny — Deny a pending request.
	 */
	public function deny_join_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		[ $space, $jr, $err ] = $this->resolve_join_request( $request );
		if ( $err ) {
			return $err;
		}

		if ( ! JoinRequest::deny( (int) $jr->id, get_current_user_id() ) ) {
			return new WP_Error( 'jetonomy_join_request_processed', __( 'This request has already been handled.', 'jetonomy' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response(
			array(
				'status'     => 'denied',
				'request_id' => (int) $jr->id,
				'space_id'   => (int) $space->id,
				'user_id'    => (int) $jr->user_id,
			),
			200
		);
	}

	/**
	 * Shared guard for approve/deny — resolve + authorize a pending request.
	 *
	 * @param WP_REST_Request $request Request carrying id + request_id.
	 * @return array{0:?object,1:?object,2:?WP_Error} [ space, join_request, error ].
	 */
	private function resolve_join_request( WP_REST_Request $request ): array {
		$id         = absint( $request->get_param( 'id' ) );
		$request_id = absint( $request->get_param( 'request_id' ) );
		$space      = Space::find( $id );
		if ( ! $space ) {
			return array( null, null, $this->not_found( 'Space' ) );
		}
		if ( ! \Jetonomy\Permissions\Permission_Engine::is_space_privileged( get_current_user_id(), $id ) ) {
			return array( null, null, $this->permission_error() );
		}
		$jr = JoinRequest::find( $request_id );
		if ( ! $jr || (int) $jr->space_id !== $id || 'pending' !== $jr->status ) {
			return array(
				null,
				null,
				new WP_Error(
					'jetonomy_join_request_not_found',
					__( 'Join request not found or already processed.', 'jetonomy' ),
					array( 'status' => 404 )
				),
			);
		}
		return array( $space, $jr, null );
	}

	/**
	 * PATCH /spaces/{id}/members/{user_id} — Update a member's role.
	 *
	 * Requires the current user to be a space admin.
	 */
	public function update_member_role( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = absint( $request->get_param( 'id' ) );
		$user_id = absint( $request->get_param( 'user_id' ) );
		$role    = sanitize_text_field( $request->get_param( 'role' ) );
		$space   = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		if ( ! in_array( $role, self::VALID_ROLES, true ) ) {
			return $this->validation_error(
				/* translators: %s: comma-separated list of valid space roles. */
				sprintf( __( 'Invalid role. Allowed: %s.', 'jetonomy' ), implode( ', ', self::VALID_ROLES ) )
			);
		}

		$current_user_id = get_current_user_id();

		if ( ! $this->is_space_admin( $id, $current_user_id ) ) {
			return $this->permission_error();
		}

		if ( ! SpaceMember::is_member( $id, $user_id ) ) {
			return new WP_Error(
				'jetonomy_not_member',
				sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'User does not belong to this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				array( 'status' => 404 )
			);
		}

		// 1.4.0 G4 — role-change integrity guards. Two independent checks.
		// Self-demotion fires FIRST so the error message is the precise one
		// (a user demoting themself when also the last admin would otherwise
		// see the last-admin error, which is technically true but less
		// actionable — they should be told plainly they cannot remove their
		// own admin status).
		$current_role = SpaceMember::get_role( $id, $user_id );

		if ( $user_id === $current_user_id && 'admin' === $current_role && 'admin' !== $role ) {
			return new WP_Error(
				'jetonomy_cannot_self_demote',
				__( 'You cannot remove your own admin role. Ask another admin to do it.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		// One role-update path: set_role() owns the write, cache-bust, the
		// role-changed event and the last-admin rule (shared with wp-admin).
		// Permission and self-demotion stay REST policy.
		$result = SpaceMember::set_role( $id, $user_id, $role, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'updated'  => true,
				'space_id' => $id,
				'user_id'  => $user_id,
				'role'     => $role,
			),
			200
		);
	}

	/**
	 * POST /spaces/{id}/invite — Generate an invite link (space admin only).
	 */
	public function generate_invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		if ( ! $this->is_space_admin( $id, $user_id ) ) {
			return $this->permission_error();
		}

		$max_uses   = absint( $request->get_param( 'max_uses' ) );
		$expires_at = InviteLink::expiry_utc( $request->get_param( 'expires_at' ) );
		if ( is_wp_error( $expires_at ) ) {
			return $expires_at;
		}

		$token  = InviteLink::generate( $id, $user_id, $max_uses, $expires_at );
		$invite = '' !== $token ? InviteLink::find_by_token( $token ) : null;
		if ( ! $invite ) {
			return new WP_Error( 'jetonomy_invite_failed', __( 'The invite link could not be created. Please try again.', 'jetonomy' ), array( 'status' => 500 ) );
		}

		// Read the row back so the response carries the same shape GET
		// /spaces/{id}/invites returns, including the `id` the caller needs to
		// revoke the link it just made, and the stored (UTC) expiry.
		return new WP_REST_Response( array_merge( $this->prepare_invite( $invite ), array( 'token' => $token ) ), 201 );
	}

	/**
	 * Build the public invite URL for a token.
	 *
	 * The admin AJAX handler keeps its own copy of this (with a comment saying
	 * it mirrors this method) because free's admin layer must not depend on a
	 * REST controller being loaded. Within this controller there is one copy.
	 */
	private function invite_url( string $token ): string {
		return \Jetonomy\route_url( 'invite', $token );
	}

	/**
	 * Shape one invite row for a REST response.
	 *
	 * Deliberately omits the raw token: the URL already carries it, and every
	 * consumer wants a link, not a secret to concatenate itself.
	 */
	private function prepare_invite( object $invite ): array {
		return array(
			'id'            => (int) $invite->id,
			'invite_url'    => $this->invite_url( (string) $invite->token ),
			'max_uses'      => (int) $invite->max_uses,
			'use_count'     => (int) $invite->use_count,
			'expires_at'    => $invite->expires_at,
			'expires_label' => InviteLink::expiry_label( $invite ),
			'is_valid'      => InviteLink::is_valid( $invite ),
		);
	}

	/**
	 * GET /spaces/{id}/invites — List a space's invite links (space admin only).
	 */
	public function get_invites( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		// Space ADMIN, not merely privileged — matching generate_invite(). An
		// invite token is a bearer credential into a space that may be hidden;
		// listing them is disclosing them, so a moderator is not enough. Note
		// the route's permission_callback is only the community-visibility
		// gate; this is the check that actually authorises the caller.
		if ( ! $this->is_space_admin( $id, get_current_user_id() ) ) {
			return $this->permission_error();
		}

		$pagination = $this->get_pagination( $request );
		$items      = array_map( array( $this, 'prepare_invite' ), InviteLink::list_by_space( $id, $pagination['limit'], $pagination['offset'] ) );

		return $this->paginated_response(
			$items,
			array(
				'total'  => InviteLink::count_by_space( $id ),
				'offset' => $pagination['offset'],
			)
		);
	}

	/**
	 * DELETE /spaces/{id}/invites/{invite_id} — Revoke a link (space admin only).
	 */
	public function revoke_invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id        = absint( $request->get_param( 'id' ) );
		$invite_id = absint( $request->get_param( 'invite_id' ) );
		$space     = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		if ( ! $this->is_space_admin( $id, get_current_user_id() ) ) {
			return $this->permission_error();
		}

		// InviteLink::revoke() scopes its DELETE to BOTH the row id and the
		// space, so a link belonging to another space cannot be revoked through
		// this space's route even if its id is guessed. A false return is
		// therefore "no such link in THIS space" — a 404, not a failure.
		if ( ! InviteLink::revoke( $invite_id, $id ) ) {
			return $this->not_found( 'Invite link' );
		}

		return new WP_REST_Response(
			array(
				'revoked'  => true,
				'id'       => $invite_id,
				'space_id' => $id,
			),
			200
		);
	}

	/**
	 * POST /invite/{token} — Validate and use an invite link.
	 */
	public function use_invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = sanitize_text_field( $request->get_param( 'token' ) );

		// Shared token → membership flow (also drives the invite landing
		// template; audit B consolidation).
		$result = InviteLink::accept( $token, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			if ( 'jetonomy_login_required' === $result->get_error_code() ) {
				// The landing template uses the error's space context; the
				// API contract is just the 401.
				return new WP_Error( 'jetonomy_login_required', $result->get_error_message(), array( 'status' => 401 ) );
			}
			return $result;
		}

		return new WP_REST_Response(
			array(
				'status'     => $result['status'],
				'space_id'   => (int) $result['space']->id,
				'space_slug' => $result['space']->slug,
			),
			200
		);
	}


	/**
	 * Format a space member row for API output.
	 */
	private function prepare_member( object $member ): array {
		$user_id = (int) $member->user_id;
		$user    = get_userdata( $user_id );
		$profile = UserProfile::find_by_user( $user_id );

		return array(
			'space_id'         => (int) $member->space_id,
			'user_id'          => $user_id,
			'role'             => $member->role,
			'joined_at'        => $member->joined_at ?? null,
			'display_name'     => $user ? \Jetonomy\user_display_name( $user ) : '',
			'avatar_url'       => \Jetonomy\Avatar::display_url( $user_id, 48 ),
			'trust_level'      => $profile ? (int) $profile->trust_level : 0,
			'reputation'       => $profile ? (int) $profile->reputation : 0,
			// Presence: mirrors the feed/reply author fields so the app can render
			// the same "online" dot on member avatars. GMT variant is the
			// clock-safe one the app prefers (see utils/presence.ts).
			'last_seen_at'     => $profile ? $profile->last_seen_at : null,
			'last_seen_at_gmt' => \Jetonomy\to_iso8601_z( $profile ? $profile->last_seen_at : null ),
			'profile_url'      => \Jetonomy\get_profile_url( (int) $user_id ),
		);
	}

	/**
	 * Determine whether a user has space admin privileges.
	 *
	 * Thin backward-compat wrapper — the canonical primitive is
	 * Permission_Engine::is_space_admin (takes user_id, space_id).
	 */
	private function is_space_admin( int $space_id, int $user_id ): bool {
		return \Jetonomy\Permissions\Permission_Engine::is_space_admin( $user_id, $space_id );
	}

	/**
	 * Generate a unique space slug.
	 */
	private function unique_slug( string $base_slug ): string {
		// Delegates to the model so REST and the Pro provisioner share one
		// uniqueness rule (Space::unique_slug) instead of drifting copies.
		return Space::unique_slug( $base_slug );
	}

	/**
	 * Query args for list_items.
	 */
	private function get_list_args(): array {
		return array_merge(
			$this->get_collection_params(),
			array(
				'category_id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'type'           => array( 'type' => 'string' ),
				'visibility'     => array(
					'type' => 'string',
					'enum' => array( 'public', 'private', 'hidden' ),
				),
				'movable_by_me'  => array(
					'type'        => 'boolean',
					'description' => 'Only spaces the current user may move posts into (move_posts).',
				),
				'postable_by_me' => array(
					'type'              => 'boolean',
					'default'           => false,
					// jt-terms-ok: REST schema description for API developers.
					'description'       => __( 'Return only spaces the current user is a member of AND can create posts in. Logged-out callers receive an empty array.', 'jetonomy' ),
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return array(
			'category_id' => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
			),
			'type'        => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => Space::valid_types(),
			),
			'title'       => array(
				'type'     => 'string',
				'required' => true,
			),
			'slug'        => array(
				'type'     => 'string',
				'required' => false,
			),
			'description' => array(
				'type'     => 'string',
				'required' => false,
			),
			'visibility'  => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'public', 'private', 'hidden' ),
			),
			'join_policy' => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => self::VALID_JOIN_POLICIES,
			),
			'icon'        => array(
				'type'     => 'string',
				'required' => false,
			),
			'cover_image' => array(
				'type'     => 'string',
				'required' => false,
				'format'   => 'uri',
			),
			'sort_order'  => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
			),
			'settings'    => array( 'required' => false ),
		);
	}

	/**
	 * Args for update_item (all optional).
	 */
	private function get_update_args(): array {
		$args = $this->get_create_args();
		foreach ( $args as &$arg ) {
			$arg['required'] = false;
		}
		return $args;
	}
}
