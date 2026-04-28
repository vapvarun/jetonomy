<?php
/**
 * Auth REST API controller.
 *
 * Powers the Login block's quick-login (and, in v1.4.0 A.3, quick-register)
 * via REST instead of admin-ajax. Replaces `wp_ajax_nopriv_jetonomy_quick_login`
 * shipped on `Jetonomy\Blocks` — see Phase A of v1.4.0.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Auth_Controller extends Base_Controller {

	protected $rest_base = 'auth';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/login',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'login' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'user_login'    => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'user_password' => [
							'required' => true,
							'type'     => 'string',
						],
						'remember'      => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/register',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'register_user' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'username'      => [
							'required' => true,
							'type'     => 'string',
						],
						'email'         => [
							'required'          => true,
							'type'              => 'string',
							'format'            => 'email',
							'sanitize_callback' => 'sanitize_email',
						],
						'password'      => [
							'required' => true,
							'type'     => 'string',
						],
						'captcha_token' => [
							'required' => false,
							'type'     => 'string',
							'default'  => '',
						],
					],
				],
			]
		);
	}

	/**
	 * POST /jetonomy/v1/auth/login — authenticate via wp_signon().
	 *
	 * Public endpoint. Rate-limited per IP. Returns a generic 401 on bad
	 * credentials so failures do not leak which half of the credential
	 * pair was wrong. wp_signon() sets the auth cookie internally before
	 * returning; the client just needs to reload to pick up the session.
	 *
	 * @param WP_REST_Request $request Body: user_login, user_password, remember?
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ) {
		if ( ! self::check_rate_limit( 'login' ) ) {
			return new WP_Error(
				'jetonomy_rate_limited',
				__( 'Too many attempts. Please wait a minute and try again.', 'jetonomy' ),
				[ 'status' => 429 ]
			);
		}

		$user_login    = (string) $request->get_param( 'user_login' );
		$user_password = (string) $request->get_param( 'user_password' );
		$remember      = (bool) $request->get_param( 'remember' );

		if ( '' === $user_login || '' === $user_password ) {
			return new WP_Error(
				'jetonomy_missing_credentials',
				__( 'Enter your username and password.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		$user = wp_signon(
			[
				'user_login'    => $user_login,
				'user_password' => $user_password,
				'remember'      => $remember,
			],
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'jetonomy_invalid_credentials',
				__( 'Incorrect username or password.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Signed in. Reloading…', 'jetonomy' ),
			]
		);
	}

	/**
	 * POST /jetonomy/v1/auth/register — create a new user account.
	 *
	 * Public endpoint. Honours the WP `users_can_register` option as a hard
	 * gate. Calls `Captcha_Manager::verify_or_skip()` so a configured CAPTCHA
	 * provider actually runs (the legacy `ajax_quick_register` had the
	 * adapter loaded but never invoked it — silent gap closed in A.3).
	 *
	 * On success the new account is auto-signed-in (parity with the legacy
	 * handler) so the page reload after submit lands the user in the
	 * community already authenticated.
	 *
	 * @param WP_REST_Request $request Body: username, email, password,
	 *                                 captcha_token? (only required when a
	 *                                 CAPTCHA adapter is configured).
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_user( WP_REST_Request $request ) {
		if ( ! (bool) get_option( 'users_can_register' ) ) {
			return new WP_Error(
				'jetonomy_registration_disabled',
				__( 'Registration is disabled on this site.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! self::check_rate_limit( 'register' ) ) {
			return new WP_Error(
				'jetonomy_rate_limited',
				__( 'Too many attempts. Please wait a minute and try again.', 'jetonomy' ),
				[ 'status' => 429 ]
			);
		}

		$username = sanitize_user( (string) $request->get_param( 'username' ), true );
		$email    = (string) $request->get_param( 'email' );
		$password = (string) $request->get_param( 'password' );
		$token    = (string) $request->get_param( 'captcha_token' );

		if ( '' === $username || '' === $email || '' === $password ) {
			return new WP_Error(
				'jetonomy_missing_fields',
				__( 'All fields are required.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! validate_username( $username ) || username_exists( $username ) ) {
			return new WP_Error(
				'jetonomy_username_unavailable',
				__( 'That username is unavailable.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			return new WP_Error(
				'jetonomy_email_unavailable',
				__( 'That email is unavailable.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error(
				'jetonomy_password_too_short',
				__( 'Password must be at least 8 characters.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		// CAPTCHA — closes the silent gap from the legacy handler. verify_or_skip
		// returns null when the feature is disabled OR the caller is trusted; we
		// treat null + true as pass, false as reject.
		$remote_ip      = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$captcha_result = \Jetonomy\Captcha\Captcha_Manager::verify_or_skip( 0, $token, $remote_ip );
		if ( false === $captcha_result ) {
			return new WP_Error(
				'jetonomy_captcha_failed',
				__( 'CAPTCHA verification failed. Please try again.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'jetonomy_create_user_failed',
				$user_id->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		// Notifier owns branded welcome + admin emails via this hook; we
		// intentionally do NOT call wp_send_new_user_notifications() to avoid
		// duplicate sends (parity with the legacy handler comment).
		do_action( 'jetonomy_user_registered', (int) $user_id );

		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, false, is_ssl() );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Account created. Reloading…', 'jetonomy' ),
			]
		);
	}

	/**
	 * Per-IP rate limit shared across login + register. 5 attempts per minute.
	 *
	 * @param string $bucket 'login' or 'register'.
	 * @return bool True when within limit, false when exhausted.
	 */
	protected static function check_rate_limit( string $bucket ): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		$key  = 'jt_auth_' . $bucket . '_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 5 ) {
			return false;
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
