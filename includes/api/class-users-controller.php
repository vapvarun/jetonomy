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
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_account' ],
					// Apple 5.1.1(v) / GDPR Art. 17: an app supporting account
					// creation must let a member delete their account from
					// inside the app — including a banned or never-verified
					// one (neither carve-out exists in either requirement).
					// allow_banned / allow_unverified skip those two gates
					// while every other auth_mutation() check (login, cookie
					// nonce) still applies.
					'permission_callback' => REST_Auth::auth_mutation(
						'read',
						[
							'allow_banned'     => true,
							'allow_unverified' => true,
						]
					),
					'args'                => $this->get_delete_account_args(),
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

		// Loaded once, outside the loop — never call blocked_ids()/is_blocked()
		// per row.
		$blocked_ids = \Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() );

		$out = array();
		foreach ( $users as $u ) {
			if ( in_array( (int) $u->ID, $blocked_ids, true ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (int) $u->ID,
				'login'        => $u->user_login,
				'display_name' => $u->display_name,
				'avatar_url'   => \Jetonomy\Avatar::display_url( $u->ID, 48 ),
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
			// avatar_url = the raw stored upload (null when none) for the edit flow;
			// avatar_display = the resolved render value ('' => client shows initials).
			'avatar_url'       => $profile->avatar_url ?? null,
			'avatar_display'   => $profile->avatar_url ?: \Jetonomy\Avatar::display_url( $id, 96 ),
			'created_at'       => $wp_user->user_registered ?? null,
			'last_seen_at'     => $profile->last_seen_at ?? null,
			// Has the CURRENT viewer blocked this profile? Backs the app's
			// Block/Unblock button. blocked_ids() is memoized per-request, so
			// this is never a fresh query. Always false for guests.
			'is_blocked'       => in_array( $id, \Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() ), true ),
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
			'avatar_url'       => $profile->avatar_url ?? null,
			'avatar_display'   => $profile->avatar_url ?: \Jetonomy\Avatar::display_url( $id, 96 ),
			'created_at'       => $wp_user->user_registered ?? null,
			'last_seen_at'     => $profile->last_seen_at ?? null,
			'is_blocked'       => in_array( $id, \Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() ), true ),
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

				// Must cover every type shown in the Edit-Profile + admin
				// Notification Defaults UIs, or that toggle is silently dropped on
				// save (idea_status_changed / moderation / join_request were missing).
				$valid_types = [ 'reply_to_post', 'reply_to_reply', 'mention', 'vote_on_post', 'accepted_answer', 'new_post_in_sub', 'badge_earned', 'idea_status_changed', 'moderation', 'join_request' ];
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
	 * DELETE /users/me — Permanently delete the authenticated user's account.
	 *
	 * Apple Guideline 5.1.1(v) / GDPR Art. 17. Body:
	 *   { password?: string, confirm: "DELETE", delete_content?: bool }
	 *
	 * Content policy defaults to ANONYMIZE, not hard-delete. This is already
	 * the plugin's shipped, tested contract — Privacy::erase_data() (GDPR
	 * eraser) and Privacy::on_user_delete() (this route, via wp_delete_user())
	 * both reassign authored posts/replies/revisions to the author_id = 0
	 * tombstone rather than deleting the rows. A hard-deleting account-delete
	 * route would contradict the plugin's own eraser, and hard-delete also
	 * destroys OTHER members' data: deleting a member's topic deletes the
	 * thread N other members replied in, orphaning their replies and
	 * drifting the denormalized counters. Apple's requirement is to delete
	 * the ACCOUNT, not the content; GDPR Art. 17 covers personal data, and
	 * pseudonymized (author_id = 0) content satisfies it — Art. 17(3)
	 * additionally permits retention for freedom of expression. `delete_content:
	 * true` is an explicit, non-default opt-in for a member who wants their
	 * own posts/replies actually removed too.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_account( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Refuse admins outright — deleting the last administrator from a
		// phone is a foot-gun with no upside. Site owners manage their own
		// account from wp-admin's Users screen.
		if ( current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'jetonomy_admin_must_use_wp_admin',
				__( 'Site administrators can\'t delete their account from the app. Use the WordPress admin Users screen instead.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! self::check_rate_limit( 'delete_account', 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error(
				'jetonomy_rate_limited',
				__( 'Too many attempts. Please wait a while and try again.', 'jetonomy' ),
				[ 'status' => 429 ]
			);
		}

		$wp_user = get_userdata( $user_id );
		if ( ! $wp_user ) {
			return $this->not_found( 'User' );
		}

		// "Type DELETE to confirm" is the only guard available to accounts
		// with no usable password (SSO / social login) and is always required.
		$confirm = (string) $request->get_param( 'confirm' );
		if ( ! hash_equals( 'DELETE', $confirm ) ) {
			return new WP_Error(
				'jetonomy_confirm_required',
				__( 'Type DELETE to confirm you want to permanently delete your account.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		// SSO / social-login accounts are commonly provisioned with no usable
		// WP password — an empty user_pass is the detectable signal. Those
		// accounts rely on the confirm check above only; every other account
		// must additionally prove the current password.
		$has_password = '' !== (string) $wp_user->user_pass;
		if ( $has_password ) {
			$password = (string) $request->get_param( 'password' );
			if ( '' === $password || ! wp_check_password( $password, $wp_user->user_pass, $user_id ) ) {
				return new WP_Error(
					'jetonomy_bad_password',
					__( 'That password is incorrect.', 'jetonomy' ),
					[ 'status' => 403 ]
				);
			}
		}

		$delete_content = (bool) $request->get_param( 'delete_content' );

		if ( $delete_content ) {
			$this->hard_delete_authored_content( $user_id );
		}

		// Preserve uploaded media by default. wp_delete_user( $uid, null )
		// would otherwise hard-delete every 'attachment' post this user
		// owns (core default: delete_with_user = true for attachments) even
		// though their (anonymized, surviving) replies still reference those
		// images. Captured before the delete call, reassigned afterward.
		$attachment_ids = [];
		if ( ! $delete_content ) {
			$attachment_ids = get_posts(
				[
					'post_type'              => 'attachment',
					'author'                 => $user_id,
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);
			add_filter( 'post_types_to_delete_with_user', [ $this, 'exclude_attachments_from_user_delete' ] );
		}

		$deleted = $this->delete_wp_account( $user_id );

		if ( ! $delete_content ) {
			remove_filter( 'post_types_to_delete_with_user', [ $this, 'exclude_attachments_from_user_delete' ] );
			$this->reassign_attachments_to_tombstone( $attachment_ids );
		}

		if ( ! $deleted ) {
			return new WP_Error(
				'jetonomy_delete_account_failed',
				__( "We couldn't delete your account. Please try again or contact support.", 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		// End the session — the account (and any cookie tied to it) is gone.
		wp_clear_auth_cookie();

		return new WP_REST_Response(
			[
				'deleted'        => true,
				'user_id'        => $user_id,
				'content_policy' => $delete_content ? 'deleted' : 'anonymized',
			],
			200
		);
	}

	/**
	 * Hard-delete every post/reply this user authored — the `delete_content:
	 * true` opt-in only. Routed through the SAME REST endpoints a member uses
	 * to delete their own content one at a time (DELETE /posts/{id},
	 * DELETE /replies/{id}) via an internal rest_do_request(), rather than
	 * calling Post::delete() / Reply::delete() directly. Those controllers
	 * are the only place that both decrements space/user counters
	 * (Post::update()/Reply::update() detect the publish→trash transition)
	 * AND fires jetonomy_after_delete_post / jetonomy_after_delete_reply,
	 * which the Pro attachments extension listens to for its own cleanup —
	 * calling the bare model delete() would silently skip both.
	 */
	private function hard_delete_authored_content( int $user_id ): void {
		// Scale note: this loop is synchronous inside the account-deletion
		// request, one rest_do_request() per authored row. Fine for a typical
		// member; a heavy contributor with thousands of posts could push the
		// request close to PHP's execution-time limit. delete_content is an
		// explicit, non-default opt-in — acceptable for v1. If this becomes a
		// real-world timeout, move it to a background job (Action Scheduler)
		// per the plugin's background-jobs standard rather than inlining a
		// batch/cursor here.
		global $wpdb;
		$posts_t   = table( 'posts' );
		$replies_t = table( 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$posts_t} WHERE author_id = %d AND status != 'trash'", $user_id ) );
		foreach ( $post_ids as $post_id ) {
			rest_do_request( new WP_REST_Request( 'DELETE', '/jetonomy/v1/posts/' . (int) $post_id ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reply_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$replies_t} WHERE author_id = %d AND status != 'trash'", $user_id ) );
		foreach ( $reply_ids as $reply_id ) {
			rest_do_request( new WP_REST_Request( 'DELETE', '/jetonomy/v1/replies/' . (int) $reply_id ) );
		}
	}

	/**
	 * Delete (or, on multisite, remove-from-site) the WP account itself.
	 *
	 * Single-site: wp_delete_user(). Multisite: defaults to
	 * remove_user_from_blog() for JUST this site — wp_delete_user() would
	 * remove the account from the ENTIRE network, which is almost never what
	 * a single community site owner wants or is authorized to decide for a
	 * shared network account. A network that genuinely owns its users (one
	 * community = one network) can opt into the network-wide delete via the
	 * `jetonomy_delete_account_network_wide` filter.
	 *
	 * Both remove_user_from_blog() and wpmu_delete_user() are hooked to
	 * Jetonomy's Privacy::on_user_delete() (both free and Pro — see
	 * class-privacy.php), so table cleanup happens automatically regardless
	 * of which path runs.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private function delete_wp_account( int $user_id ): bool {
		if ( is_multisite() ) {
			/**
			 * Whether account deletion should remove the member from the
			 * ENTIRE multisite network rather than just the current site.
			 * Default false: a shared network account is not this site's to
			 * fully delete. Flip to true only when this community owns the
			 * whole network (one community = one network).
			 *
			 * @since 1.7.1
			 * @param bool $network_wide Default false.
			 * @param int  $user_id      Account being deleted.
			 */
			$network_wide = (bool) apply_filters( 'jetonomy_delete_account_network_wide', false, $user_id );

			if ( $network_wide ) {
				if ( ! function_exists( 'wpmu_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
				}
				return (bool) wpmu_delete_user( $user_id );
			}

			$result = remove_user_from_blog( $user_id, get_current_blog_id() );
			return ! is_wp_error( $result );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		return (bool) wp_delete_user( $user_id );
	}

	/**
	 * `post_types_to_delete_with_user` filter callback — strips 'attachment'
	 * so wp_delete_user()/wpmu_delete_user() leave the leaver's uploaded
	 * media in place. Public: WP's call_user_func() invokes filter callbacks
	 * from outside the class, which fails on private/protected methods.
	 */
	public function exclude_attachments_from_user_delete( array $post_types ): array {
		return array_values( array_diff( $post_types, [ 'attachment' ] ) );
	}

	/**
	 * Reassign the leaver's preserved attachments to the author_id = 0
	 * tombstone (same convention as ANON_TABLES) so their surviving,
	 * anonymized posts/replies keep working images instead of pointing at a
	 * deleted account.
	 *
	 * @param int[] $attachment_ids
	 */
	private function reassign_attachments_to_tombstone( array $attachment_ids ): void {
		if ( empty( $attachment_ids ) ) {
			return;
		}

		global $wpdb;
		$ids          = array_map( 'absint', $attachment_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_author = 0 WHERE ID IN ({$placeholders})", ...$ids ) );

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
	}

	/**
	 * Args for DELETE /users/me.
	 */
	private function get_delete_account_args(): array {
		return [
			'password'       => [
				'type'     => 'string',
				'required' => false,
			],
			'confirm'        => [
				'type'     => 'string',
				'required' => true,
			],
			'delete_content' => [
				'type'     => 'boolean',
				'required' => false,
				'default'  => false,
			],
		];
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
		$tbl        = table( 'posts' );
		$spaces_tbl = table( 'spaces' );

		// Space-visibility + per-post is_private gate so private/hidden-space
		// posts (and private posts in public spaces) stay hidden from
		// non-members / non-authors. Cross-space context → $space_id null.
		// is_anonymous = 0 (both queries below) is an anonymity guard: an
		// anonymous post must never surface on the real author's public
		// profile stream, even to the author themselves — that would
		// deanonymize it by correlation.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		[ $priv_sql, $priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );

		$gate_sql    = '';
		$gate_params = [];
		if ( '1=1' !== $space_vis_sql ) {
			$gate_sql   .= ' AND ' . $space_vis_sql;
			$gate_params = array_merge( $gate_params, $space_vis_params );
		}
		if ( '' !== $priv_sql ) {
			$gate_sql   .= ' AND ' . $priv_sql;
			$gate_params = array_merge( $gate_params, $priv_params );
		}

		// Hide this profile's posts entirely when the VIEWER has blocked $id.
		// no-op for guests/no-blocks; must match the COUNT(*) below exactly.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			$gate_sql .= ' AND ' . $block_sql;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.* FROM {$tbl} p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$gate_sql}
				 ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
				$id,
				...array_merge( $gate_params, [ $limit, $offset ] )
			)
		) ?: [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$tbl} p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$gate_sql}",
				$id,
				...$gate_params
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
			'id'             => (int) ( $profile->user_id ?? 0 ),
			'user_id'        => (int) ( $profile->user_id ?? 0 ),
			'reputation'     => (int) ( $profile->reputation ?? 0 ),
			'post_count'     => (int) ( $profile->post_count ?? 0 ),
			'reply_count'    => (int) ( $profile->reply_count ?? 0 ),
			'trust_level'    => (int) ( $profile->trust_level ?? 0 ),
			'bio'            => $profile->bio ?? null,
			'avatar_url'     => $profile->avatar_url ?? null,
			'avatar_display' => $profile->avatar_url ?: \Jetonomy\Avatar::display_url( (int) ( $profile->user_id ?? 0 ), 96 ),
			'last_seen_at'   => $profile->last_seen_at ?? null,
			'created_at'     => $profile->created_at ?? null,
			'updated_at'     => $profile->updated_at ?? null,
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
