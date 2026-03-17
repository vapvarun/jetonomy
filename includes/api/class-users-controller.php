<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Post;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Trust\Trust_Levels;
use function Jetonomy\table;

class Users_Controller extends Base_Controller {

	protected string $rest_base = 'users';

	/**
	 * Register all REST routes for users.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		// Current-user routes.
		register_rest_route( $ns, '/users/me', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_current_user' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_current_user' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_update_args(),
			],
		] );

		// Public profile by ID.
		register_rest_route( $ns, '/users/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_item' ],
			'permission_callback' => '__return_true',
		] );

		// Posts by user.
		register_rest_route( $ns, '/users/(?P<id>\d+)/posts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_user_posts' ],
			'permission_callback' => '__return_true',
			'args'                => $this->get_collection_params(),
		] );
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
		$spaces_count = count( SpaceMember::list_user_spaces( $user_id ) );

		return new WP_REST_Response(
			array_merge(
				$this->prepare_profile( $profile ),
				[
					'email'               => $wp_user->user_email,
					'display_name'        => $wp_user->display_name,
					'trust_level_name'    => Trust_Levels::name( $trust_level ),
					'spaces_joined_count' => $spaces_count,
					'settings'            => UserProfile::get_settings( $user_id ),
				]
			),
			200
		);
	}

	/**
	 * GET /users/{id} — Return a public profile (no sensitive data).
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		// update display_name via wp_update_user.
		if ( null !== $request->get_param( 'display_name' ) ) {
			$display_name = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
			if ( ! empty( $display_name ) ) {
				wp_update_user( [
					'ID'           => $user_id,
					'display_name' => $display_name,
				] );
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

		return $this->paginated_response( $items, [
			'total'    => $total,
			'has_more' => count( $items ) === $limit,
		] );
	}

	/**
	 * Format a UserProfile row for API output.
	 */
	private function prepare_profile( ?object $profile ): array {
		return [
			'id'          => (int) ( $profile->user_id ?? 0 ),
			'user_id'     => (int) ( $profile->user_id ?? 0 ),
			'reputation'  => (int) ( $profile->reputation ?? 0 ),
			'post_count'  => (int) ( $profile->post_count ?? 0 ),
			'reply_count' => (int) ( $profile->reply_count ?? 0 ),
			'trust_level' => (int) ( $profile->trust_level ?? 0 ),
			'bio'         => $profile->bio ?? null,
			'avatar_url'  => $profile->avatar_url ?? null,
			'last_seen_at' => $profile->last_seen_at ?? null,
			'created_at'  => $profile->created_at ?? null,
			'updated_at'  => $profile->updated_at ?? null,
		];
	}

	/**
	 * Format a post row for inclusion in user post listings.
	 */
	private function prepare_post( object $post ): array {
		return [
			'id'         => (int) $post->id,
			'space_id'   => (int) $post->space_id,
			'title'      => $post->title ?? '',
			'slug'       => $post->slug ?? '',
			'type'       => $post->type ?? 'topic',
			'status'     => $post->status ?? 'publish',
			'vote_score' => (int) ( $post->vote_score ?? 0 ),
			'reply_count' => (int) ( $post->reply_count ?? 0 ),
			'view_count' => (int) ( $post->view_count ?? 0 ),
			'created_at' => $post->created_at ?? null,
		];
	}

	/**
	 * Args for PATCH /users/me.
	 */
	private function get_update_args(): array {
		return [
			'display_name' => [ 'type' => 'string', 'required' => false ],
			'bio'          => [ 'type' => 'string', 'required' => false ],
			'avatar_url'   => [ 'type' => 'string', 'required' => false, 'format' => 'uri' ],
			'settings'     => [ 'type' => 'object', 'required' => false ],
		];
	}
}
