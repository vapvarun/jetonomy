<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.2.4 — simplify access control + unify default_space_type key.
 *
 * Two settings cleanups that restore sanity to the admin UI:
 *
 *   1. `require_login` is removed. Its admin label ("Require login to participate")
 *      never matched the code, which redirected every page view to wp-login.php.
 *      Writes are already login-gated at the REST layer, so the setting's only
 *      real effect was the read-side redirect. On upgrade, if `require_login=1`
 *      we flip `guest_read` to false so the private-community intent is preserved.
 *
 *   2. `default_type` (written by the setup wizard) is renamed to
 *      `default_space_type` (expected by the admin Settings page). The two keys
 *      pointed at the same conceptual setting but never talked to each other.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_2_4 {

	public function up(): void {
		$settings = get_option( 'jetonomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$changed = false;

		// 1. require_login → migrate to guest_read semantics and drop the key.
		if ( array_key_exists( 'require_login', $settings ) ) {
			if ( ! empty( $settings['require_login'] ) ) {
				// Admin wanted force-login; preserve that by turning off public reads.
				$settings['guest_read'] = false;
			}
			unset( $settings['require_login'] );
			$changed = true;
		}

		// 2. default_type (setup wizard) → default_space_type (admin settings).
		if ( array_key_exists( 'default_type', $settings ) ) {
			if ( empty( $settings['default_space_type'] ) ) {
				$settings['default_space_type'] = $settings['default_type'];
			}
			unset( $settings['default_type'] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'jetonomy_settings', $settings );
		}
	}
}
