<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

class Spaces_Controller extends Base_Controller {

	protected string $rest_base = 'spaces';

	/**
	 * Valid member roles.
	 */
	private const VALID_ROLES = [ 'viewer', 'member', 'moderator', 'admin' ];

	/**
	 * Valid join policies.
	 */
	private const VALID_JOIN_POLICIES = [ 'open', 'approval', 'invite' ];

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		// Collection routes.
		register_rest_route( $ns, '/spaces', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_list_args(),
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'create_permission_check' ],
				'args'                => $this->get_create_args(),
			],
		] );

		// Single space routes.
		register_rest_route( $ns, '/spaces/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'update_permission_check' ],
				'args'                => $this->get_update_args(),
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'update_permission_check' ],
			],
		] );

		// Member collection routes.
		register_rest_route( $ns, '/spaces/(?P<id>\d+)/members', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_members' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'join_space' ],
				'permission_callback' => [ $this, 'require_login_check' ],
			],
		] );

		// Individual member routes.
		register_rest_route( $ns, '/spaces/(?P<id>\d+)/members/(?P<user_id>\d+)', [
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'leave_space' ],
				'permission_callback' => [ $this, 'require_login_check' ],
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_member_role' ],
				'permission_callback' => [ $this, 'require_login_check' ],
				'args'                => [
					'role' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => self::VALID_ROLES,
					],
				],
			],
		] );
	}

	/**
	 * Permission check: requires login.
	 */
	public function require_login_check(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'jetonomy_unauthorized',
				__( 'You must be logged in.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	/**
	 * Permission check: requires jetonomy_create_spaces capability.
	 */
	public function create_permission_check(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'jetonomy_unauthorized',
				__( 'You must be logged in.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}
		if ( ! current_user_can( 'jetonomy_create_spaces' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * Permission check: requires login. Space-admin check is done inside the handler.
	 */
	public function update_permission_check(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'jetonomy_unauthorized',
				__( 'You must be logged in.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	/**
	 * GET /spaces — List spaces with optional filters.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$user_id     = get_current_user_id();
		$category_id = $request->get_param( 'category_id' ) ? absint( $request->get_param( 'category_id' ) ) : null;
		$type        = $request->get_param( 'type' ) ? sanitize_text_field( $request->get_param( 'type' ) ) : null;
		$visibility  = $request->get_param( 'visibility' ) ? sanitize_text_field( $request->get_param( 'visibility' ) ) : null;

		global $wpdb;
		$table  = $this->spaces_table();
		$where  = [];
		$values = [];

		if ( $category_id ) {
			$where[]  = 'category_id = %d';
			$values[] = $category_id;
		}

		if ( $type ) {
			$where[]  = 'type = %s';
			$values[] = $type;
		}

		// Build visibility filter: always show public; show private/hidden only if member.
		if ( $visibility ) {
			$where[]  = 'visibility = %s';
			$values[] = $visibility;
		}

		$sql = 'SELECT * FROM ' . $table;

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY sort_order ASC, title ASC';

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) ) ?: [];
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $sql ) ?: [];
		}

		// Filter out private/hidden spaces the user cannot see.
		$filtered = [];
		foreach ( $results as $space ) {
			if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
				if ( $user_id && SpaceMember::is_member( (int) $space->id, $user_id ) ) {
					$filtered[] = $this->prepare_space( $space );
				}
			} else {
				$filtered[] = $this->prepare_space( $space );
			}
		}

		return $this->paginated_response( $filtered, [ 'total' => count( $filtered ) ] );
	}

	/**
	 * GET /spaces/{id} — Get a single space.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		// Private/hidden spaces require membership.
		if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
			if ( ! $user_id || ! SpaceMember::is_member( $id, $user_id ) ) {
				return $this->permission_error();
			}
		}

		return new WP_REST_Response( $this->prepare_space( $space ), 200 );
	}

	/**
	 * POST /spaces — Create a new space.
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$title = sanitize_text_field( $request->get_param( 'title' ) );

		if ( empty( $title ) ) {
			return $this->validation_error( __( 'Space title is required.', 'jetonomy' ) );
		}

		$slug = $request->get_param( 'slug' )
			? sanitize_title( $request->get_param( 'slug' ) )
			: sanitize_title( $title );

		$slug = $this->unique_slug( $slug );

		// Handle settings: accept array or JSON string.
		$settings_raw = $request->get_param( 'settings' );
		$settings     = '';
		if ( $settings_raw ) {
			if ( is_array( $settings_raw ) ) {
				$settings = wp_json_encode( $settings_raw );
			} else {
				$decoded = json_decode( $settings_raw, true );
				$settings = is_array( $decoded ) ? wp_json_encode( $decoded ) : '';
			}
		}

		$data = [
			'category_id'  => absint( $request->get_param( 'category_id' ) ) ?: null,
			'title'        => $title,
			'slug'         => $slug,
			'description'  => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'type'         => sanitize_text_field( (string) $request->get_param( 'type' ) ) ?: 'forum',
			'visibility'   => sanitize_text_field( (string) $request->get_param( 'visibility' ) ) ?: 'public',
			'join_policy'  => sanitize_text_field( (string) $request->get_param( 'join_policy' ) ) ?: 'open',
			'icon'         => sanitize_text_field( (string) $request->get_param( 'icon' ) ),
			'settings'     => $settings,
			'author_id'    => get_current_user_id(),
		];

		// Remove empty optional fields so DB defaults apply.
		$data = array_filter( $data, fn( $v ) => null !== $v && '' !== $v );

		$id = Space::create( $data );

		if ( ! $id ) {
			return new WP_Error(
				'jetonomy_create_failed',
				__( 'Failed to create space.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		// Auto-add creator as admin.
		SpaceMember::add( $id, get_current_user_id(), 'admin' );

		$space = Space::find( $id );

		return new WP_REST_Response( $this->prepare_space( $space ), 201 );
	}

	/**
	 * PATCH /spaces/{id} — Partially update a space (space admin or manage_options).
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		if ( ! $this->is_space_admin( $id, $user_id ) ) {
			return $this->permission_error();
		}

		$data = [];

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
			$data['category_id'] = absint( $request->get_param( 'category_id' ) ) ?: null;
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
		if ( null !== $request->get_param( 'icon' ) ) {
			$data['icon'] = sanitize_text_field( $request->get_param( 'icon' ) );
		}
		if ( null !== $request->get_param( 'settings' ) ) {
			$settings_raw = $request->get_param( 'settings' );
			if ( is_array( $settings_raw ) ) {
				$data['settings'] = wp_json_encode( $settings_raw );
			} else {
				$decoded = json_decode( $settings_raw, true );
				$data['settings'] = is_array( $decoded ) ? wp_json_encode( $decoded ) : '';
			}
		}

		if ( empty( $data ) ) {
			return $this->validation_error( __( 'No fields provided for update.', 'jetonomy' ) );
		}

		$data['updated_at'] = \Jetonomy\now();
		Space::update( $id, $data );

		$updated = Space::find( $id );

		return new WP_REST_Response( $this->prepare_space( $updated ), 200 );
	}

	/**
	 * DELETE /spaces/{id} — Delete a space (space admin or manage_options).
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		if ( ! $this->is_space_admin( $id, $user_id ) ) {
			return $this->permission_error();
		}

		$deleted = Space::delete( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'jetonomy_delete_failed',
				__( 'Failed to delete space.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	/**
	 * GET /spaces/{id}/members — List all members of a space.
	 */
	public function get_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		// Private/hidden spaces: only members can see the member list.
		if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
			if ( ! $user_id || ! SpaceMember::is_member( $id, $user_id ) ) {
				return $this->permission_error();
			}
		}

		$members = SpaceMember::list_by_space( $id );
		$items   = array_map( [ $this, 'prepare_member' ], $members );

		return $this->paginated_response( $items, [ 'total' => count( $items ) ] );
	}

	/**
	 * POST /spaces/{id}/members — Join a space.
	 *
	 * - open:     adds user as member immediately.
	 * - approval: creates a pending join request (stored as a custom option; no JoinRequest model exists yet).
	 * - invite:   not allowed — returns 403.
	 */
	public function join_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$space = Space::find( $id );

		if ( ! $space ) {
			return $this->not_found( 'Space' );
		}

		$user_id = get_current_user_id();

		if ( SpaceMember::is_member( $id, $user_id ) ) {
			return new WP_Error(
				'jetonomy_already_member',
				__( 'You are already a member of this space.', 'jetonomy' ),
				[ 'status' => 409 ]
			);
		}

		$join_policy = $space->join_policy ?? 'open';

		if ( 'invite' === $join_policy ) {
			return new WP_Error(
				'jetonomy_invite_only',
				__( 'This space is invite-only.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		if ( 'approval' === $join_policy ) {
			// Store pending request in options table until a JoinRequest model is available.
			$pending_key = 'jetonomy_join_request_' . $id . '_' . $user_id;
			update_option( $pending_key, [
				'space_id'    => $id,
				'user_id'     => $user_id,
				'requested_at' => \Jetonomy\now(),
				'status'      => 'pending',
			], false );

			return new WP_REST_Response( [
				'status'  => 'pending',
				'message' => __( 'Join request submitted. Awaiting approval.', 'jetonomy' ),
			], 202 );
		}

		// open policy: add immediately.
		SpaceMember::add( $id, $user_id, 'member' );

		return new WP_REST_Response( [
			'status'   => 'joined',
			'space_id' => $id,
			'user_id'  => $user_id,
			'role'     => 'member',
		], 201 );
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
				__( 'User is not a member of this space.', 'jetonomy' ),
				[ 'status' => 404 ]
			);
		}

		SpaceMember::remove( $id, $user_id );

		return new WP_REST_Response( [
			'removed'   => true,
			'space_id'  => $id,
			'user_id'   => $user_id,
		], 200 );
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
				__( 'User is not a member of this space.', 'jetonomy' ),
				[ 'status' => 404 ]
			);
		}

		global $wpdb;
		$wpdb->update(
			\Jetonomy\table( 'space_members' ),
			[ 'role' => $role ],
			[ 'space_id' => $id, 'user_id' => $user_id ]
		);

		return new WP_REST_Response( [
			'updated'  => true,
			'space_id' => $id,
			'user_id'  => $user_id,
			'role'     => $role,
		], 200 );
	}

	/**
	 * Format a space object for API output.
	 */
	private function prepare_space( object $space ): array {
		return [
			'id'              => (int) $space->id,
			'category_id'     => $space->category_id ? (int) $space->category_id : null,
			'title'           => $space->title,
			'slug'            => $space->slug,
			'description'     => $space->description ?? '',
			'type'            => $space->type ?? 'forum',
			'visibility'      => $space->visibility ?? 'public',
			'join_policy'     => $space->join_policy ?? 'open',
			'icon'            => $space->icon ?? '',
			'settings'        => ! empty( $space->settings ) ? json_decode( $space->settings, true ) : [],
			'member_count'    => (int) ( $space->member_count ?? 0 ),
			'post_count'      => (int) ( $space->post_count ?? 0 ),
			'sort_order'      => (int) ( $space->sort_order ?? 0 ),
			'author_id'       => $space->author_id ? (int) $space->author_id : null,
			'created_at'      => $space->created_at ?? null,
			'updated_at'      => $space->updated_at ?? null,
			'last_activity_at' => $space->last_activity_at ?? null,
		];
	}

	/**
	 * Format a space member row for API output.
	 */
	private function prepare_member( object $member ): array {
		return [
			'space_id'  => (int) $member->space_id,
			'user_id'   => (int) $member->user_id,
			'role'      => $member->role,
			'joined_at' => $member->joined_at ?? null,
		];
	}

	/**
	 * Determine whether a user has space admin privileges.
	 *
	 * Returns true for WP admins (manage_options) or users with
	 * the 'admin' role in the space.
	 */
	private function is_space_admin( int $space_id, int $user_id ): bool {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$role = SpaceMember::get_role( $space_id, $user_id );
		return 'admin' === $role;
	}

	/**
	 * Generate a unique space slug.
	 */
	private function unique_slug( string $base_slug ): string {
		$slug    = $base_slug;
		$counter = 1;

		while ( Space::find_by_slug( $slug ) ) {
			$slug = $base_slug . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Return the fully-qualified spaces table name.
	 */
	private function spaces_table(): string {
		return \Jetonomy\table( 'spaces' );
	}

	/**
	 * Query args for list_items.
	 */
	private function get_list_args(): array {
		return array_merge( $this->get_collection_params(), [
			'category_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'type'        => [ 'type' => 'string' ],
			'visibility'  => [ 'type' => 'string', 'enum' => [ 'public', 'private', 'hidden' ] ],
		] );
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return [
			'category_id' => [ 'type' => 'integer', 'required' => false, 'minimum' => 1 ],
			'type'        => [ 'type' => 'string', 'required' => false ],
			'title'       => [ 'type' => 'string', 'required' => true ],
			'slug'        => [ 'type' => 'string', 'required' => false ],
			'description' => [ 'type' => 'string', 'required' => false ],
			'visibility'  => [ 'type' => 'string', 'required' => false, 'enum' => [ 'public', 'private', 'hidden' ] ],
			'join_policy' => [ 'type' => 'string', 'required' => false, 'enum' => self::VALID_JOIN_POLICIES ],
			'icon'        => [ 'type' => 'string', 'required' => false ],
			'settings'    => [ 'required' => false ],
		];
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
