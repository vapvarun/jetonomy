<?php
/**
 * Google reCAPTCHA v3 adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies reCAPTCHA v3 tokens against Google's siteverify endpoint.
 */
class Recaptcha_Adapter implements Captcha_Adapter {

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
	 * Minimum score to treat as human (0.0–1.0).
	 *
	 * @var float
	 */
	private float $score_threshold;

	/**
	 * Constructor.
	 *
	 * @param string $site_key        reCAPTCHA v3 site key.
	 * @param string $secret_key      reCAPTCHA v3 secret key.
	 * @param float  $score_threshold Minimum score to treat as human (0.0–1.0). Default 0.5.
	 */
	public function __construct( string $site_key, string $secret_key, float $score_threshold = 0.5 ) {
		$this->site_key        = $site_key;
		$this->secret_key      = $secret_key;
		$this->score_threshold = $score_threshold;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'recaptcha_v3';
	}

	/**
	 * Verify a reCAPTCHA v3 token.
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
			'https://www.google.com/recaptcha/api/siteverify',
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

		return ! empty( $body['success'] ) && ( (float) ( $body['score'] ?? 0 ) ) >= $this->score_threshold;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_script_url(): string {
		return 'https://www.google.com/recaptcha/api.js?render=' . esc_attr( $this->site_key );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_site_key(): string {
		return $this->site_key;
	}

	/**
	 * reCAPTCHA v3 is invisible — tokens come from grecaptcha.execute(), no
	 * container element is needed.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_widget(): string {
		return '';
	}
}
