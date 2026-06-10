<?php
/**
 * Cloudflare Turnstile CAPTCHA adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies Cloudflare Turnstile tokens.
 */
class Turnstile_Adapter implements Captcha_Adapter {

	/**
	 * Site key for the frontend widget.
	 *
	 * @var string
	 */
	private string $site_key;

	/**
	 * Secret key for server-side verification.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Constructor.
	 *
	 * @param string $site_key   Cloudflare Turnstile site key.
	 * @param string $secret_key Cloudflare Turnstile secret key.
	 */
	public function __construct( string $site_key, string $secret_key ) {
		$this->site_key   = $site_key;
		$this->secret_key = $secret_key;
	}

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'turnstile';
	}

	/**
	 * Verify a Turnstile token.
	 *
	 * @param string $token     The token from the frontend widget.
	 * @param string $remote_ip Client IP address.
	 * @return bool
	 */
	public function verify( string $token, string $remote_ip = '' ): bool {
		if ( empty( $token ) || empty( $this->secret_key ) ) {
			return false;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'body'    => array(
					'secret'   => $this->secret_key,
					'response' => $token,
					'remoteip' => $remote_ip,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ! empty( $body['success'] );
	}

	/**
	 * Get the frontend script URL.
	 *
	 * @return string
	 */
	public function get_script_url(): string {
		return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	}

	/**
	 * Get the site key.
	 *
	 * @return string
	 */
	public function get_site_key(): string {
		return $this->site_key;
	}

	/**
	 * Visible widget container. The Turnstile api.js auto-renders every
	 * `.cf-turnstile` element on load and injects its own hidden
	 * `cf-turnstile-response` input inside the container, which the submit
	 * JS reads scoped to the surrounding form.
	 *
	 * `data-size="flexible"` lets the widget shrink to the form's width on
	 * mobile instead of overflowing at its fixed 300px default.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_widget(): string {
		return '<div class="cf-turnstile jt-captcha-widget" data-sitekey="' . esc_attr( $this->site_key ) . '" data-size="flexible"></div>';
	}
}
