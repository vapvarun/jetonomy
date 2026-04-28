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
	 * Per-IP rate limit shared across login + register. 5 attempts per minute.
	 *
	 * Lives here in the REST controller during A.2 + A.3. The legacy
	 * `Jetonomy\Blocks::check_rate_limit` still exists in parallel because
	 * `ajax_quick_register` keeps using it until A.3 commit 3 deletes both.
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
