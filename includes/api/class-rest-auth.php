<?php
/**
 * REST authentication helper.
 *
 * Provides reusable permission_callback factories so every Jetonomy mutation
 * route shares one consistent auth contract: login + cookie-nonce + cap check
 * + optional post-author check. Centralising this removes the copy-pasted
 * `current_user_can()` blocks that drifted across 20+ controllers in 1.3.x.
 *
 * Each factory returns a Closure(WP_REST_Request) that resolves to either
 * `true` (passes) or a `WP_Error` with the right HTTP status. Wire it
 * directly into `register_rest_route()`:
 *
 *     register_rest_route( 'jetonomy/v1', '/posts', [
 *         'methods'             => 'POST',
 *         'callback'            => [ $this, 'create_post' ],
 *         'permission_callback' => \Jetonomy\API\REST_Auth::auth_mutation(
 *             'jetonomy_create_posts',
 *             [ 'author_of_post' => fn( $r ) => (int) $r['post_id'] ]
 *         ),
 *     ] );
 *
 * @package Jetonomy
 * @since   1.4.3
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use Closure;
use WP_Error;
use WP_REST_Request;

/**
 * REST authentication helper.
 *
 * @since 1.4.3
 */
class REST_Auth {

	/**
	 * Build a permission_callback for an authenticated mutation route.
	 *
	 * Enforces (in order):
	 *   1. Logged-in user (401 rest_not_logged_in if not).
	 *   2. Valid cookie nonce when the request is cookie-authenticated
	 *      (403 rest_cookie_invalid_nonce if missing/invalid). Header-auth
	 *      requests (Application Passwords, OAuth, JWT) skip the nonce check.
	 *   3. At least one of the supplied capabilities (403 rest_forbidden if
	 *      none match).
	 *   4. Optional post-author check via `$opts['author_of_post']`. The
	 *      callable receives the WP_REST_Request and must return an int
	 *      post ID; the current user must own the post (or hold
	 *      `manage_options`) for the callback to pass.
	 *
	 * @since 1.4.3
	 *
	 * @param string|string[] $caps Capability or list of capabilities (OR).
	 *                              Defaults to `'read'` so the helper still
	 *                              enforces login + nonce when callers don't
	 *                              care about a specific cap.
	 * @param array           $opts Optional. {
	 *     @type callable|null $author_of_post   Receives WP_REST_Request, must
	 *                                           return int post ID. If given,
	 *                                           the current user must own the
	 *                                           post (or hold manage_options).
	 *     @type bool          $allow_banned     Skip the banned-account gate.
	 *                                           Only for routes a banned member
	 *                                           must still reach — e.g. account
	 *                                           deletion (Apple 5.1.1(v) / GDPR
	 *                                           Art. 17 apply regardless of a
	 *                                           moderation action). Default false.
	 *     @type bool          $allow_unverified Skip the pending-email-verification
	 *                                           gate. Same rationale — a visitor
	 *                                           who never confirmed their email
	 *                                           must still be able to delete the
	 *                                           account they created. Default false.
	 * }
	 * @return Closure(WP_REST_Request): (true|WP_Error)
	 */
	public static function auth_mutation( $caps = 'read', array $opts = array() ): Closure {
		return static function ( WP_REST_Request $request ) use ( $caps, $opts ) {
			// 1. Logged-in?
			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'rest_not_logged_in',
					__( 'You must be logged in to perform this action.', 'jetonomy' ),
					array( 'status' => 401 )
				);
			}

			// 1b. Account-status gate. Application Passwords mint credentials
			// OUTSIDE the wp_authenticate flow, so the `authenticate`-filter
			// bans/verification gates (class-jetonomy.php) never run for a
			// header-authed app/API call. Enforce them here so one place covers
			// every mutation route (posts, replies, votes, conversations,
			// push device registration, …) on the mobile/native client.
			$current_uid = get_current_user_id();

			if (
				empty( $opts['allow_banned'] )
				&& $current_uid > 0
				&& class_exists( '\\Jetonomy\\Models\\Restriction' )
				&& \Jetonomy\Models\Restriction::is_banned( $current_uid )
			) {
				return new WP_Error(
					'jetonomy_user_banned',
					__( 'Your account has been banned from this community.', 'jetonomy' ),
					array( 'status' => 403 )
				);
			}

			if ( empty( $opts['allow_unverified'] ) && $current_uid > 0 && get_user_meta( $current_uid, '_jetonomy_pending_verification', true ) ) {
				return new WP_Error(
					'jetonomy_pending_verification',
					__( 'Confirm your email to finish signing up before posting.', 'jetonomy' ),
					array( 'status' => 403 )
				);
			}

			// 2. Cookie-auth path requires a valid X-WP-Nonce. Header-auth
			// paths (Application Passwords, OAuth) authenticate per-request
			// and don't need (or use) the WP cookie nonce.
			$is_cookie_auth = ! empty( $_COOKIE )
				&& empty( $_SERVER['HTTP_AUTHORIZATION'] )
				&& empty( $_SERVER['PHP_AUTH_USER'] );

			if ( $is_cookie_auth ) {
				$nonce = $request->get_header( 'x_wp_nonce' );
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return new WP_Error(
						'rest_cookie_invalid_nonce',
						__( 'Cookie nonce is invalid.', 'jetonomy' ),
						array( 'status' => 403 )
					);
				}
			}

			// 3. Capability check. Accept string or array; pass if ANY match.
			$cap_list = is_array( $caps ) ? $caps : array( $caps );
			$cap_list = array_filter( array_map( 'strval', $cap_list ) );

			if ( ! empty( $cap_list ) ) {
				$passed = false;
				foreach ( $cap_list as $cap ) {
					if ( current_user_can( $cap ) ) {
						$passed = true;
						break;
					}
				}
				if ( ! $passed ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'You do not have permission to perform this action.', 'jetonomy' ),
						array( 'status' => 403 )
					);
				}
			}

			// 4. Post-author check (optional).
			if ( isset( $opts['author_of_post'] ) && is_callable( $opts['author_of_post'] ) ) {
				$post_id = (int) call_user_func( $opts['author_of_post'], $request );

				// Site admins always pass — they own the moderation workflow.
				if ( ! current_user_can( 'manage_options' ) ) {
					// Jetonomy does not ship an `edit_jetonomy_post` capability
					// today, so resolve ownership against the Post model.
					// Falls back to `false` (deny) if the post can't be found,
					// matching WP's `edit_post` behavior on missing IDs.
					$is_author = false;
					if ( $post_id > 0 && class_exists( '\\Jetonomy\\Models\\Post' ) ) {
						$post = \Jetonomy\Models\Post::find( $post_id );
						if ( $post && (int) $post->author_id === (int) get_current_user_id() ) {
							$is_author = true;
						}
					}
					if ( ! $is_author ) {
						return new WP_Error(
							'rest_forbidden',
							__( 'You can only edit your own posts.', 'jetonomy' ),
							array( 'status' => 403 )
						);
					}
				}
			}

			return true;
		};
	}

	/**
	 * Build a permission_callback for a public (logged-out-friendly) mutation.
	 *
	 * Use sparingly — only for routes that intentionally accept anonymous
	 * writes (e.g. quick-register, password reset, webhook receivers that
	 * authenticate via signature instead of WP auth).
	 *
	 * Rate limiting is part of the API contract but not enforced here yet —
	 * `$opts['rate_limit']` is captured so callers can declare intent today
	 * and have it transparently enforced once the limiter lands.
	 *
	 * @since 1.4.3
	 *
	 * @param array $opts Optional. {
	 *     @type int|string|null $rate_limit Tag the limiter should bucket
	 *                                       this route under. Currently
	 *                                       stored only; no enforcement.
	 * }
	 * @return Closure(WP_REST_Request): (true|WP_Error)
	 */
	public static function auth_public_write( array $opts = array() ): Closure {
		// TODO rate-limit: enforce `$opts['rate_limit']` once Rate_Limiter
		// gains a per-route bucket API. Tracked as part of WS2-B.
		$_rate_limit = $opts['rate_limit'] ?? null; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		return static function ( WP_REST_Request $request ) {
			unset( $request ); // Reserved for future limiter hooks.
			return true;
		};
	}
}
