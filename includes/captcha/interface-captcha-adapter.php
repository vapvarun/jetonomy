<?php
/**
 * CAPTCHA adapter interface.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for CAPTCHA provider adapters.
 */
interface Captcha_Adapter {

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Verify a CAPTCHA token.
	 *
	 * @param string $token     The token from the frontend widget.
	 * @param string $remote_ip Client IP address.
	 * @return bool True if verification passes.
	 */
	public function verify( string $token, string $remote_ip = '' ): bool;

	/**
	 * Get the frontend script URL to load.
	 *
	 * @return string
	 */
	public function get_script_url(): string;

	/**
	 * Get the site key for the frontend widget.
	 *
	 * @return string
	 */
	public function get_site_key(): string;
}
