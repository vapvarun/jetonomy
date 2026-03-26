<?php
/**
 * CAPTCHA manager — reads settings, instantiates provider, verifies tokens.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Central CAPTCHA manager for reCAPTCHA v3 and Cloudflare Turnstile.
 */
class Captcha_Manager {

	/**
	 * Active CAPTCHA adapter instance, or null if disabled.
	 *
	 * @var Captcha_Adapter|null
	 */
	private static ?Captcha_Adapter $adapter = null;

	/**
	 * Initialize the CAPTCHA system based on saved settings.
	 * Called once during plugin bootstrap (plugins_loaded).
	 */
	public static function init(): void {
		$settings = get_option( 'jetonomy_settings', array() );
		$provider = $settings['captcha_provider'] ?? 'none';

		if ( 'none' === $provider ) {
			return;
		}

		$site_key   = $settings['captcha_site_key'] ?? '';
		$secret_key = $settings['captcha_secret_key'] ?? '';

		if ( empty( $site_key ) || empty( $secret_key ) ) {
			return;
		}

		if ( 'recaptcha_v3' === $provider ) {
			self::$adapter = new Recaptcha_Adapter(
				$site_key,
				$secret_key,
				(float) ( $settings['captcha_score_threshold'] ?? 0.5 )
			);
		} elseif ( 'turnstile' === $provider ) {
			self::$adapter = new Turnstile_Adapter( $site_key, $secret_key );
		}

		if ( self::$adapter && ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		}
	}

	/**
	 * Enqueue the CAPTCHA provider script on frontend pages.
	 */
	public static function enqueue_scripts(): void {
		if ( ! self::$adapter ) {
			return;
		}

		wp_enqueue_script(
			'jetonomy-captcha-provider',
			self::$adapter->get_script_url(),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external versioned URL
			array( 'in_footer' => true )
		);

		wp_add_inline_script(
			'jetonomy-captcha-provider',
			sprintf(
				'window.jtCaptcha = %s;',
				wp_json_encode(
					array(
						'provider' => self::$adapter->get_name(),
						'siteKey'  => self::$adapter->get_site_key(),
					)
				)
			),
			'before'
		);
	}

	/**
	 * Verify CAPTCHA token, or skip for trusted/admin users.
	 *
	 * Returns:
	 *   null  — CAPTCHA disabled or user is trusted (no action needed).
	 *   true  — Verification passed.
	 *   false — Verification failed; caller should reject the request.
	 *
	 * @param int    $user_id   Current user ID.
	 * @param string $token     CAPTCHA token from the request.
	 * @param string $remote_ip Client IP address.
	 * @return bool|null
	 */
	public static function verify_or_skip( int $user_id, string $token, string $remote_ip = '' ): ?bool {
		if ( ! self::$adapter ) {
			return null; // CAPTCHA disabled.
		}

		// Skip verification for administrators.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return null;
		}

		// Skip for trust level 2+ users (established community members).
		$profile = \Jetonomy\Models\UserProfile::find_by_user( $user_id );
		if ( $profile && (int) ( $profile->trust_level ?? 0 ) >= 2 ) {
			return null; // Trusted — skip.
		}

		if ( empty( $token ) ) {
			return false;
		}

		return self::$adapter->verify( $token, $remote_ip );
	}

	/**
	 * Check whether a CAPTCHA provider is currently configured and active.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return null !== self::$adapter;
	}
}
