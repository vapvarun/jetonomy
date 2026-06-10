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
use Jetonomy\API\REST_Auth;

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
					'permission_callback' => REST_Auth::auth_public_write( [ 'rate_limit' => 'login' ] ),
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
						'captcha_token' => [
							'required' => false,
							'type'     => 'string',
							'default'  => '',
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
					'permission_callback' => REST_Auth::auth_public_write( [ 'rate_limit' => 'register' ] ),
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
						// Honeypot — must be empty. Bots that auto-fill every
						// field will populate this; real users never see it
						// (rendered with display:none + autocomplete=off in
						// the Login block markup).
						'website'       => [
							'required' => false,
							'type'     => 'string',
							'default'  => '',
						],
						// Page-loaded timestamp (Unix seconds). Real users
						// take seconds to fill the form; bots submit instantly.
						// Reject anything < 2s or > 1h since the page rendered.
						'loaded_at'     => [
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/lost-password',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'lost_password' ],
					'permission_callback' => REST_Auth::auth_public_write( [ 'rate_limit' => 'lost_password' ] ),
					'args'                => [
						'user_login'    => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
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

		// Verify email — visitor clicks the link in the welcome email
		// and lands on this GET endpoint. We consume the token, mark the
		// account verified, drop the auth cookie, and redirect into the
		// community surface. GET (not POST) so the email link works as a
		// plain href without JS.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/verify-email',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'verify_email' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'user_id' => [
							'required' => true,
							'type'     => 'integer',
						],
						'token'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// Resend verification — visitor lost the email, asks for another.
		// Always returns a generic success so the response can't be used
		// to probe which emails have pending accounts.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/resend-verification',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'resend_verification' ],
					'permission_callback' => REST_Auth::auth_public_write( [ 'rate_limit' => 'resend_verification' ] ),
					'args'                => [
						'user_login' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
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

		// CAPTCHA gate (1.5.0 — parity with register/lost-password, the one
		// auth endpoint that was missing it). Anonymous user_id 0;
		// verify_or_skip returns null when no adapter is configured.
		$token          = (string) $request->get_param( 'captcha_token' );
		$remote_ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$captcha_result = \Jetonomy\Captcha\Captcha_Manager::verify_or_skip( 0, $token, $remote_ip );
		if ( false === $captcha_result ) {
			return new WP_Error(
				'jetonomy_captcha_failed',
				__( 'CAPTCHA verification failed. Please try again.', 'jetonomy' ),
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
			// Preserve Jetonomy-specific authenticate-filter errors so the
			// visitor sees the actual reason (banned account, pending email
			// verification). All other WP errors collapse to a generic
			// "incorrect credentials" message to prevent username probing.
			$err_code = $user->get_error_code();
			if ( in_array( $err_code, array( 'jetonomy_user_banned', 'jetonomy_pending_verification' ), true ) ) {
				return new WP_Error(
					$err_code,
					$user->get_error_message(),
					[ 'status' => 403 ]
				);
			}
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

		// Anti-spam layer 1 — honeypot.
		// Real users never see the "website" field (display:none in the block
		// markup). Auto-filling form-spam bots populate every input they see,
		// so a non-empty value is a near-certain bot signal.
		$honeypot = (string) $request->get_param( 'website' );
		if ( '' !== trim( $honeypot ) ) {
			// Same generic error so the bot can't tell which field tripped it.
			return new WP_Error(
				'jetonomy_invalid_signup',
				__( 'Could not create the account. Please try again.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		// Anti-spam layer 2 — time-on-form gate.
		// Real visitors take more than 2 seconds to fill out 3 fields. If
		// loaded_at is unset (older Login block markup) we skip the check.
		$loaded_at = (int) $request->get_param( 'loaded_at' );
		if ( $loaded_at > 0 ) {
			$elapsed = time() - $loaded_at;
			if ( $elapsed < 2 || $elapsed > HOUR_IN_SECONDS ) {
				return new WP_Error(
					'jetonomy_invalid_signup',
					__( 'Could not create the account. Please try again.', 'jetonomy' ),
					array( 'status' => 400 )
				);
			}
		}

		// Anti-spam layer 3 — disposable email blocklist.
		// Sites that need stricter enforcement should turn on email
		// verification (which forces a real, reachable inbox). The list is
		// filterable so admins can extend or override.
		if ( self::is_disposable_email( $email ) ) {
			return new WP_Error(
				'jetonomy_disposable_email',
				__( 'Please use a permanent email address. Disposable mailboxes are not accepted.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

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

		$jt_settings = get_option( 'jetonomy_settings', array() );
		$require_ev  = ! empty( $jt_settings['require_email_verification'] );

		if ( $require_ev ) {
			// Issue a one-time verification token. Stored as a hash so a
			// readable copy of usermeta can't be replayed against the verify
			// endpoint. Plain token only travels in the email link.
			$token      = wp_generate_password( 40, false );
			$token_hash = wp_hash_password( $token );
			$expires_at = time() + DAY_IN_SECONDS;

			update_user_meta( (int) $user_id, '_jetonomy_pending_verification', 1 );
			update_user_meta( (int) $user_id, '_jetonomy_verification_token_hash', $token_hash );
			update_user_meta( (int) $user_id, '_jetonomy_verification_token_expires', $expires_at );
			update_user_meta( (int) $user_id, '_jetonomy_verification_sent_at', time() );

			\Jetonomy\Notifications\Notifier::send_verification_email( (int) $user_id, $token );

			// Intentionally do NOT fire `jetonomy_user_registered` here — that
			// hook ships the branded welcome email which would arrive before
			// the visitor confirms their email and read as if the account is
			// already live. The verify-email handler fires the action once
			// the account is actually usable.
			do_action( 'jetonomy_user_pending_verification', (int) $user_id );

			$masked = $this->mask_email( $email );
			return rest_ensure_response(
				[
					'success'               => true,
					'requires_verification' => true,
					/* translators: %s: masked email address (e.g. j***@example.com) */
					'message'               => sprintf( __( 'Account created. Check %s for a confirmation link to finish signing up.', 'jetonomy' ), $masked ),
				]
			);
		}

		// Default flow — no verification required, sign the user in immediately.
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
	 * GET /jetonomy/v1/auth/verify-email — consume a verification token.
	 *
	 * Visitor lands here from the email link. On success we mark the account
	 * verified, set the auth cookie, and 302-redirect to the community home
	 * so the visitor sees a fully-rendered forum and not a JSON blob.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_email( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$token   = (string) $request->get_param( 'token' );

		if ( ! $user_id || '' === $token ) {
			return $this->verification_error( __( 'This confirmation link is missing details. Try the link from your email again.', 'jetonomy' ) );
		}

		$pending = (bool) get_user_meta( $user_id, '_jetonomy_pending_verification', true );
		if ( ! $pending ) {
			return $this->verification_error( __( 'This account is already confirmed. You can log in directly.', 'jetonomy' ) );
		}

		$stored_hash = (string) get_user_meta( $user_id, '_jetonomy_verification_token_hash', true );
		$expires_at  = (int) get_user_meta( $user_id, '_jetonomy_verification_token_expires', true );

		if ( '' === $stored_hash || ! wp_check_password( $token, $stored_hash ) ) {
			return $this->verification_error( __( 'This confirmation link is invalid. Request a new one from the sign-in page.', 'jetonomy' ) );
		}
		if ( $expires_at > 0 && time() > $expires_at ) {
			return $this->verification_error( __( 'This confirmation link has expired. Request a new one from the sign-in page.', 'jetonomy' ) );
		}

		// Token good — flip the user to verified.
		delete_user_meta( $user_id, '_jetonomy_pending_verification' );
		delete_user_meta( $user_id, '_jetonomy_verification_token_hash' );
		delete_user_meta( $user_id, '_jetonomy_verification_token_expires' );
		delete_user_meta( $user_id, '_jetonomy_verification_sent_at' );
		update_user_meta( $user_id, '_jetonomy_verified_at', time() );

		do_action( 'jetonomy_email_verified', $user_id );

		// Now that the account is actually usable, fire the standard
		// registered hook so Notifier sends the branded welcome email.
		do_action( 'jetonomy_user_registered', $user_id );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		$redirect = \Jetonomy\base_url() . '/?verified=1';
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * POST /jetonomy/v1/auth/resend-verification — issue a fresh token email.
	 *
	 * Always returns the same generic success regardless of whether the
	 * account exists or is in pending state — same account-enumeration
	 * policy as lost-password.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_verification( WP_REST_Request $request ) {
		if ( ! self::check_rate_limit( 'resend_verification', 3, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'jetonomy_rate_limited',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'jetonomy' ),
				[ 'status' => 429 ]
			);
		}

		$generic = rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'If an account is waiting on confirmation for that email, a new link is on its way.', 'jetonomy' ),
			]
		);

		$login = (string) $request->get_param( 'user_login' );
		if ( '' === $login ) {
			return $generic;
		}

		$user = get_user_by( 'login', $login );
		if ( ! $user && is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
		}
		if ( ! $user ) {
			return $generic;
		}

		$pending = (bool) get_user_meta( $user->ID, '_jetonomy_pending_verification', true );
		if ( ! $pending ) {
			return $generic;
		}

		// Local cooldown: don't send more than one email per 60 seconds for
		// the same account, even within the IP rate-limit window.
		$last_sent = (int) get_user_meta( $user->ID, '_jetonomy_verification_sent_at', true );
		if ( $last_sent > 0 && ( time() - $last_sent ) < MINUTE_IN_SECONDS ) {
			return $generic;
		}

		$token      = wp_generate_password( 40, false );
		$token_hash = wp_hash_password( $token );
		$expires_at = time() + DAY_IN_SECONDS;

		update_user_meta( $user->ID, '_jetonomy_verification_token_hash', $token_hash );
		update_user_meta( $user->ID, '_jetonomy_verification_token_expires', $expires_at );
		update_user_meta( $user->ID, '_jetonomy_verification_sent_at', time() );

		\Jetonomy\Notifications\Notifier::send_verification_email( (int) $user->ID, $token );

		return $generic;
	}

	private function verification_error( string $message ): WP_Error {
		return new WP_Error(
			'jetonomy_verification_failed',
			$message,
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build a human-readable masked version of an email — local part keeps
	 * the first letter, the rest becomes "***@" and the domain is shown.
	 *
	 * @param string $email
	 * @return string
	 */
	private function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return $email;
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		$first  = substr( $local, 0, 1 );
		return $first . '***' . $domain;
	}

	/**
	 * Built-in disposable-email blocklist. Covers the high-traffic
	 * temp-mailbox providers that drive most signup spam. Site owners
	 * who need a fuller list can add to it via the
	 * `jetonomy_disposable_email_domains` filter.
	 *
	 * @param string $email
	 * @return bool
	 */
	private static function is_disposable_email( string $email ): bool {
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return false;
		}
		$domain = strtolower( substr( $email, $at + 1 ) );

		$blocked = array(
			'10minutemail.com',
			'10minutemail.net',
			'20minutemail.com',
			'30minutemail.com',
			'guerrillamail.com',
			'guerrillamail.net',
			'guerrillamail.org',
			'guerrillamail.biz',
			'sharklasers.com',
			'mailinator.com',
			'mailinator.net',
			'mailinator.org',
			'maildrop.cc',
			'getairmail.com',
			'tempmail.com',
			'temp-mail.org',
			'temp-mail.io',
			'tempmailo.com',
			'temporary-mail.net',
			'throwawaymail.com',
			'throwaway.email',
			'yopmail.com',
			'yopmail.net',
			'yopmail.org',
			'discard.email',
			'discardmail.com',
			'fakeinbox.com',
			'trashmail.com',
			'trashmail.de',
			'trashmail.net',
			'spam4.me',
			'mohmal.com',
			'mohmal.in',
			'getnada.com',
			'inboxbear.com',
			'mytemp.email',
			'tempinbox.com',
			'1secmail.com',
			'1secmail.net',
			'1secmail.org',
			'dropmail.me',
			'mintemail.com',
		);

		/**
		 * Filter the list of disposable email domains rejected on register.
		 *
		 * @param string[] $blocked Lowercased domains.
		 */
		$blocked = (array) apply_filters( 'jetonomy_disposable_email_domains', $blocked );

		return in_array( $domain, $blocked, true );
	}

	/**
	 * POST /jetonomy/v1/auth/lost-password — request a password-reset email.
	 *
	 * Public endpoint. Wraps WP core's `retrieve_password()` so the entry
	 * point lives inside Jetonomy's surface (Login block) instead of bouncing
	 * the user to the unstyled `/wp-login.php?action=lostpassword` form. The
	 * actual reset step (clicking the email link → setting a new password)
	 * stays on WP core's `wp-login.php?action=rp` flow because that is
	 * one-time-token plumbing WP already owns.
	 *
	 * Always returns the same generic success message regardless of whether
	 * the account exists. This matches WP core's account-enumeration policy
	 * and prevents probes that flip "Forgot password" into a username-checker.
	 *
	 * Rate-limited to 3 attempts / 5 minutes per IP — tighter than login
	 * (5 / minute) because the per-attempt cost is higher (sending email)
	 * and the legitimate use rate is much lower (a real human asks for a
	 * reset once or twice, never six times in a row).
	 *
	 * @param WP_REST_Request $request Body: user_login, captcha_token?
	 * @return WP_REST_Response|WP_Error
	 */
	public function lost_password( WP_REST_Request $request ) {
		if ( ! self::check_rate_limit( 'lost_password', 3, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'jetonomy_rate_limited',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'jetonomy' ),
				[ 'status' => 429 ]
			);
		}

		$user_login = (string) $request->get_param( 'user_login' );
		$token      = (string) $request->get_param( 'captcha_token' );

		if ( '' === $user_login ) {
			// 400 here is fine because empty isn't a real account anyway —
			// no enumeration leak.
			return new WP_Error(
				'jetonomy_missing_user_login',
				__( 'Enter your username or email.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		// CAPTCHA gate (parity with register). Anonymous user_id 0; verify_or_skip
		// returns null when no adapter is configured.
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

		// retrieve_password() returns true on success, WP_Error on failure
		// (invalid user / mailer error / no password reset allowed). We
		// intentionally swallow the WP_Error and respond with the same
		// generic success either way to keep account existence private.
		// `retrieve_password()` lives in wp-login.php which isn't loaded on
		// REST requests; require it explicitly.
		if ( ! function_exists( 'retrieve_password' ) ) {
			require_once ABSPATH . 'wp-login.php';
		}
		retrieve_password( $user_login );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( "If an account matches, a reset link is on its way to that account's email.", 'jetonomy' ),
			]
		);
	}

	/**
	 * Per-IP rate limit shared across login / register / lost-password.
	 * Defaults to 5 attempts per minute. Caller can override for tighter
	 * buckets (lost-password uses 3 / 5 minutes per Decision 8 plug-and-play
	 * — strict enough to discourage email-probing, loose enough that a real
	 * person who fat-fingered their email twice still gets through).
	 *
	 * @param string $bucket  'login' / 'register' / 'lost_password' / future buckets.
	 * @param int    $max     Max attempts in the window.
	 * @param int    $seconds Window size in seconds.
	 * @return bool True when within limit, false when exhausted.
	 */
	protected static function check_rate_limit( string $bucket, int $max = 5, int $seconds = MINUTE_IN_SECONDS ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		$key = 'jt_auth_' . $bucket . '_' . md5( $ip );
		$now = time();

		// 1.4.0 fix: store BOTH the hit count AND a fixed expiry timestamp
		// in the transient value, then re-`set_transient` with only the
		// REMAINING seconds. Calling `set_transient($key, $hits+1, $seconds)`
		// on every hit (the pre-1.4.0 pattern) extended the window every
		// time, so an attacker pacing themselves under $max could go
		// indefinitely. Now the TTL collapses toward $expires_at on every
		// increment, the window is fixed once, and the throttle is real.
		$record = get_transient( $key );
		if ( ! is_array( $record ) || ! isset( $record['expires_at'], $record['hits'] ) || $now >= (int) $record['expires_at'] ) {
			set_transient(
				$key,
				array(
					'hits'       => 1,
					'expires_at' => $now + $seconds,
				),
				$seconds
			);
			return true;
		}

		if ( (int) $record['hits'] >= $max ) {
			return false;
		}

		$remaining = max( 1, (int) $record['expires_at'] - $now );
		set_transient(
			$key,
			array(
				'hits'       => (int) $record['hits'] + 1,
				'expires_at' => (int) $record['expires_at'],
			),
			$remaining
		);
		return true;
	}
}
