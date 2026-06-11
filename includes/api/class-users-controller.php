<?php
/**
 * Users REST API controller.
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
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Trust\Trust_Levels;
use function Jetonomy\table;

class Users_Controller extends Base_Controller {

	protected $rest_base = 'users';

	/**
	 * Register all REST routes for users.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Current-user routes.
		register_rest_route(
			$ns,
			'/users/me',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_current_user' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_current_user' ],
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_update_args(),
				],
			]
		);

		// Public profile by ID.
		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
			]
		);

		// Public profile by login (username).
		register_rest_route(
			$ns,
			'/users/by-login/(?P<login>[a-zA-Z0-9_\-\.]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_by_login' ],
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
			]
		);

		// Posts by user.
		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/posts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_user_posts' ],
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
				'args'                => $this->get_collection_params(),
			]
		);

		// 1.4.0 C.7 — mention autocomplete: GET /users/suggest?q=&space_id=
		register_rest_route(
			$ns,
			'/users/suggest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'q'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'space_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /users/suggest — typeahead matches by login or display name.
	 *
	 * Permission: logged-in user. When `space_id` is supplied, suggestions
	 * are restricted to space members so a member can only @mention people
	 * who can actually see the post — keeps mentions inside private spaces
	 * from leaking into outside searches.
	 *
	 * Returns: top 10 matches as { id, login, display_name, avatar_url }.
	 */
	public function suggest( WP_REST_Request $request ): WP_REST_Response {
		$q        = trim( (string) $request->get_param( 'q' ) );
		$space_id = (int) $request->get_param( 'space_id' );
		// Two-letter minimum keeps the page light AND keeps the result set
		// useful for the typeahead. Empty / one-character searches return
		// empty so the dropdown doesn't flash on every keypress.
		if ( strlen( $q ) < 2 ) {
			return new WP_REST_Response( array(), 200 );
		}

		$users = array();
		if ( $space_id > 0 ) {
			global $wpdb;
			$members_tbl = table( 'space_members' );
			$ids         = (array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT user_id FROM {$members_tbl} WHERE space_id = %d",
					$space_id
				)
			);
			if ( empty( $ids ) ) {
				return new WP_REST_Response( array(), 200 );
			}
			$users = get_users(
				array(
					'include' => array_map( 'intval', $ids ),
					'search'  => '*' . $q . '*',
					'number'  => 10,
					'orderby' => 'display_name',
				)
			);
		} else {
			// Don't search user_email — that lets a logged-in member
			// fish for other members' email addresses by typing the
			// address prefix and seeing them resolve. Login + display
			// name covers every legitimate mention case.
			$users = get_users(
				array(
					'search'         => '*' . $q . '*',
					'search_columns' => array( 'user_login', 'display_name' ),
					'number'         => 10,
					'orderby'        => 'display_name',
				)
			);
		}

		$out = array();
		foreach ( $users as $u ) {
			$out[] = array(
				'id'           => (int) $u->ID,
				'login'        => $u->user_login,
				'display_name' => $u->display_name,
				'avatar_url'   => (string) get_avatar_url( $u->ID, array( 'size' => 48 ) ),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * GET /users/me — Return the authenticated user's full profile.
	 */
	public function get_current_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$wp_user = get_userdata( $user_id );
		if ( ! $wp_user ) {
			return $this->not_found( 'User' );
		}

		$profile      = UserProfile::find_or_create( $user_id );
		$trust_level  = (int) ( $profile->trust_level ?? 0 );
		$spaces_count = SpaceMember::count_user_spaces( $user_id );

		return new WP_REST_Response(
			array_merge(
				$this->prepare_profile( $profile ),
				[
					'email'               => $wp_user->user_email,
					'display_name'        => $wp_user->display_name,
					'trust_level_name'    => Trust_Levels::name( $trust_level ),
					'spaces_joined_count' => $spaces_count,
					'settings'            => UserProfile::get_settings( $user_id ),
					'email_opt_out'       => (bool) get_user_meta( $user_id, 'jetonomy_email_opt_out', true ),
				]
			),
			200
		);
	}

	/**
	 * GET /users/{id} — Return a public profile (no sensitive data).
	 */
	public function get_item( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$wp_user = get_userdata( $id );
		if ( ! $wp_user ) {
			return $this->not_found( 'User' );
		}

		$profile     = UserProfile::find_by_user( $id );
		$trust_level = (int) ( $profile->trust_level ?? 0 );

		$data = [
			'id'               => $id,
			'display_name'     => $wp_user->display_name,
			'trust_level'      => $trust_level,
			'trust_level_name' => Trust_Levels::name( $trust_level ),
			'reputation'       => (int) ( $profile->reputation ?? 0 ),
			'post_count'       => (int) ( $profile->post_count ?? 0 ),
			'reply_count'      => (int) ( $profile->reply_count ?? 0 ),
			'bio'              => $profile->bio ?? null,
			'avatar_url'       => $profile->avatar_url ?? null,
			'created_at'       => $wp_user->user_registered ?? null,
			'last_seen_at'     => $profile->last_seen_at ?? null,
		];

		/**
		 * Filter the REST response data for a single user.
		 *
		 * @param array    $data    Prepared response data.
		 * @param \WP_User $wp_user WordPress user object.
		 * @param mixed    $request WP_REST_Request or null.
		 */
		$data = apply_filters( 'jetonomy_rest_prepare_user', $data, $wp_user, $request );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /users/by-login/{login} — Return a public profile by username.
	 */
	public function get_by_login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$login = sanitize_user( $request->get_param( 'login' ) );

		$wp_user = get_user_by( 'login', $login );
		if ( ! $wp_user ) {
			return $this->not_found( 'User' );
		}

		$id          = (int) $wp_user->ID;
		$profile     = UserProfile::find_by_user( $id );
		$trust_level = (int) ( $profile->trust_level ?? 0 );

		$data = [
			'id'               => $id,
			'display_name'     => $wp_user->display_name,
			'trust_level'      => $trust_level,
			'trust_level_name' => Trust_Levels::name( $trust_level ),
			'reputation'       => (int) ( $profile->reputation ?? 0 ),
			'post_count'       => (int) ( $profile->post_count ?? 0 ),
			'reply_count'      => (int) ( $profile->reply_count ?? 0 ),
			'bio'              => $profile->bio ?? null,
			'avatar_url'       => $profile->avatar_url ?? get_avatar_url( $id, [ 'size' => 64 ] ),
			'created_at'       => $wp_user->user_registered ?? null,
			'last_seen_at'     => $profile->last_seen_at ?? null,
		];

		/** This filter is documented in includes/api/class-users-controller.php */
		$data = apply_filters( 'jetonomy_rest_prepare_user', $data, $wp_user, $request );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * PATCH /users/me — Update the authenticated user's profile.
	 */
	public function update_current_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$profile_data = [];

		if ( null !== $request->get_param( 'bio' ) ) {
			$profile_data['bio'] = sanitize_textarea_field( (string) $request->get_param( 'bio' ) );
		}

		if ( null !== $request->get_param( 'avatar_url' ) ) {
			$profile_data['avatar_url'] = esc_url_raw( (string) $request->get_param( 'avatar_url' ) );
		}

		if ( null !== $request->get_param( 'settings' ) ) {
			$settings = $request->get_param( 'settings' );
			if ( is_array( $settings ) ) {
				$profile_data['settings'] = wp_json_encode( $settings );
			}
		}

		// Handle notification_preferences — merge into existing settings JSON.
		if ( null !== $request->get_param( 'notification_preferences' ) ) {
			$notif_input = $request->get_param( 'notification_preferences' );
			if ( is_array( $notif_input ) ) {
				$existing     = UserProfile::find_by_user( $user_id );
				$cur_settings = $existing ? json_decode( $existing->settings ?? '{}', true ) : [];
				if ( ! is_array( $cur_settings ) ) {
					$cur_settings = []; }

				$valid_types = [ 'reply_to_post', 'reply_to_reply', 'mention', 'vote_on_post', 'accepted_answer', 'new_post_in_sub', 'badge_earned' ];
				$prefs       = [];
				foreach ( $notif_input as $type => $channels ) {
					if ( ! in_array( $type, $valid_types, true ) ) {
						continue;
					}
					$prefs[ $type ] = [
						'web'   => ! empty( $channels['web'] ),
						'email' => ! empty( $channels['email'] ),
					];
				}
				$cur_settings['notifications'] = $prefs;
				$profile_data['settings']      = wp_json_encode( $cur_settings );
			}
		}

		// Master email opt-out (global kill-switch the verification reminder
		// and future digests honour). Stored as user meta, not in the
		// settings JSON, because the reminder reads get_user_meta directly.
		if ( null !== $request->get_param( 'email_opt_out' ) ) {
			if ( $request->get_param( 'email_opt_out' ) ) {
				update_user_meta( $user_id, 'jetonomy_email_opt_out', 1 );
			} else {
				delete_user_meta( $user_id, 'jetonomy_email_opt_out' );
			}
		}

		// update display_name via wp_update_user.
		if ( null !== $request->get_param( 'display_name' ) ) {
			$display_name = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
			if ( ! empty( $display_name ) ) {
				wp_update_user(
					[
						'ID'           => $user_id,
						'display_name' => $display_name,
					]
				);
			}
		}

		if ( ! empty( $profile_data ) ) {
			$profile_data['updated_at'] = current_time( 'mysql' );
			UserProfile::update_profile( $user_id, $profile_data );
		}

		// Always update last_seen.
		UserProfile::update_last_seen( $user_id );

		$wp_user = get_userdata( $user_id );
		$profile = UserProfile::find_or_create( $user_id );

		return new WP_REST_Response(
			array_merge(
				$this->prepare_profile( $profile ),
				[
					'email'            => $wp_user->user_email,
					'display_name'     => $wp_user->display_name,
					'trust_level_name' => Trust_Levels::name( (int) ( $profile->trust_level ?? 0 ) ),
					'settings'         => UserProfile::get_settings( $user_id ),
					'email_opt_out'    => (bool) get_user_meta( $user_id, 'jetonomy_email_opt_out', true ),
				]
			),
			200
		);
	}

	/**
	 * GET /users/{id}/posts — Paginated list of posts by a user.
	 */
	public function get_user_posts( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! get_userdata( $id ) ) {
			return $this->not_found( 'User' );
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		global $wpdb;
		$tbl = table( 'posts' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE author_id = %d AND status = 'publish' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$id,
				$limit,
				$offset
			)
		) ?: [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$tbl} WHERE author_id = %d AND status = 'publish'",
				$id
			)
		);

		$items = array_map( [ $this, 'prepare_post' ], $posts );

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
		);
	}

	/**
	 * Format a UserProfile row for API output.
	 */
	private function prepare_profile( ?object $profile ): array {
		$data = [
			'id'           => (int) ( $profile->user_id ?? 0 ),
			'user_id'      => (int) ( $profile->user_id ?? 0 ),
			'reputation'   => (int) ( $profile->reputation ?? 0 ),
			'post_count'   => (int) ( $profile->post_count ?? 0 ),
			'reply_count'  => (int) ( $profile->reply_count ?? 0 ),
			'trust_level'  => (int) ( $profile->trust_level ?? 0 ),
			'bio'          => $profile->bio ?? null,
			'avatar_url'   => $profile->avatar_url ?? null,
			'last_seen_at' => $profile->last_seen_at ?? null,
			'created_at'   => $profile->created_at ?? null,
			'updated_at'   => $profile->updated_at ?? null,
		];

		/**
		 * Filter the user profile REST response. Extensions (e.g. custom-fields)
		 * use this to append per-user payload (custom field values, badges, etc.).
		 *
		 * @since 1.4.1
		 * @param array $data    Prepared profile response data.
		 * @param array $context { object_type: 'user', object_id: int }
		 */
		$data = apply_filters(
			'jetonomy_profile_response',
			$data,
			array(
				'object_type' => 'user',
				'object_id'   => (int) ( $profile->user_id ?? 0 ),
			)
		);

		return $data;
	}

	/**
	 * Format a post row for inclusion in user post listings.
	 */
	private function prepare_post( object $post ): array {
		return [
			'id'          => (int) $post->id,
			'space_id'    => (int) $post->space_id,
			'title'       => $post->title ?? '',
			'slug'        => $post->slug ?? '',
			'type'        => $post->type ?? 'topic',
			'status'      => $post->status ?? 'publish',
			'vote_score'  => (int) ( $post->vote_score ?? 0 ),
			'reply_count' => (int) ( $post->reply_count ?? 0 ),
			'view_count'  => (int) ( $post->view_count ?? 0 ),
			'created_at'  => $post->created_at ?? null,
		];
	}

	/**
	 * Args for PATCH /users/me.
	 */
	private function get_update_args(): array {
		return [
			'display_name'  => [
				'type'     => 'string',
				'required' => false,
			],
			'bio'           => [
				'type'     => 'string',
				'required' => false,
			],
			'avatar_url'    => [
				'type'     => 'string',
				'required' => false,
				'format'   => 'uri',
			],
			'settings'      => [
				'type'     => 'object',
				'required' => false,
			],
			'email_opt_out' => [
				'type'     => 'boolean',
				'required' => false,
			],
		];
	}
}
