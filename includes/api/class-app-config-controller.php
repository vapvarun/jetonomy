<?php
/**
 * App Config REST API controller.
 *
 * Serves white-label branding + feature flags for the mobile app so the
 * client can theme its login / splash screens BEFORE a user authenticates,
 * and hide UI for extensions the site hasn't enabled. Public read on purpose
 * (mirrors `/push/vapid-key`): there is nothing sensitive here, and pre-login
 * theming needs it without a session.
 *
 * @package Jetonomy
 * @since   1.6.0
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

class App_Config_Controller extends Base_Controller {

	protected $rest_base = 'app/config';

	/**
	 * Register the public /app/config route.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/app/config',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_config' ),
				// Public: the mobile app reads this before login to theme its
				// splash / sign-in screens. Same posture as /push/vapid-key.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /app/config — branding + feature flags.
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$settings   = (array) get_option( 'jetonomy_settings', array() );
		$pro_active = defined( 'JETONOMY_PRO_VERSION' );
		$branding   = $this->branding( $pro_active, $settings );

		// App display name: the Community Title (Settings → General), falling
		// back to the WordPress site name. Shown as the community name in-app.
		$app_name = trim( (string) ( $settings['community_title'] ?? '' ) );
		if ( '' === $app_name ) {
			$app_name = (string) get_bloginfo( 'name' );
		}

		$features = $this->feature_flags( $settings );

		$data = array(
			'app_name'     => $app_name,
			'space_label'  => array(
				// Configurable "Space" noun (Settings -> General) so the app
				// renders the same label as web, not a hardcoded "Space".
				'singular' => \Jetonomy\space_label(),
				'plural'   => \Jetonomy\space_label( true ),
			),
			'accent_color' => $branding['accent_color'],
			'logo_url'     => $branding['logo_url'],
			'login_bg_url' => $branding['login_bg_url'],
			// Pre-login EULA screen (Apple Guideline 1.2). Always a string
			// ('' = not configured) so the app can do a simple truthiness check.
			'terms_url'    => $this->terms_url( $settings ),
			'privacy_url'  => $this->privacy_url( $settings ),
			// Dark mode follows the device/OS theme in the app — phantom dark_mode_default removed (no admin producer).
			'pro_active'   => $pro_active,
			// Pro-only gate. The Jetonomy mobile app is a Pro benefit, so this
			// defaults to false here in free. Jetonomy Pro flips it true via the
			// `jetonomy_app_config` filter below, and only when the site holds a
			// valid Pro license. When false, the app shows a "requires Jetonomy
			// Pro" screen and refuses to sign in, so the app never runs against a
			// free-only (or unlicensed) install. Fail closed.
			'app_enabled'  => false,
			'features'     => $features,
			// The attachments{} object is ALWAYS present so the app has one
			// consistent shape whether or not the feature is active. Free ships
			// the defaults with enabled reflecting the flag; the Pro attachments
			// extension overrides the limits (and enabled) via the
			// jetonomy_app_config filter when it is active and licensed.
			'attachments'  => array(
				'enabled'        => $features['attachments'],
				'max_files'      => 5,
				'max_size_bytes' => 10 * 1024 * 1024,
				'allowed_types'  => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'txt', 'csv' ),
			),
			// How the app signs a member in on THIS site (Wbcom App Auth
			// standard, docs/standards/app-auth.md). Additive — an older
			// plugin simply has no `auth` key, which the app reads as "use
			// the legacy wp-admin authorize flow". One door per site: on the
			// ~60% of sites also running BuddyNext, connect_url is BN's
			// bridge, so the app inherits the site's real login (social,
			// two-factor) with zero app-side logic.
			'auth'         => \Jetonomy\Integrations\App_Connect::auth_block(),
		);

		/**
		 * Filter the mobile app config payload. Lets Pro / site owners override
		 * branding (e.g. inject a white-label logo) or force-flag a feature.
		 *
		 * @since 1.6.0
		 * @param array           $data    Assembled config payload.
		 * @param WP_REST_Request $request The config request.
		 */
		$data = apply_filters( 'jetonomy_app_config', $data, $request );

		$response = new WP_REST_Response( $data, 200 );
		// Client-tier caching only (plan WP4.15): the payload is public,
		// shared, and read once per app launch PRE-LOGIN — a client cache
		// hit removes a full WP bootstrap, where a server-side cache would
		// save three autoloaded option reads. No server cache on top
		// (ownership map: one tier per datum).
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Resolve branding. Free cannot call Pro classes, so read the shared
	 * options directly: prefer the Pro white-label row when Pro is active,
	 * otherwise fall back to the free Appearance accent color.
	 *
	 * @param bool  $pro_active Whether the Pro plugin is loaded.
	 * @param array $settings   The `jetonomy_settings` option.
	 * @return array{accent_color:string,logo_url:string,login_bg_url:string}
	 */
	private function branding( bool $pro_active, array $settings ): array {
		$accent   = '';
		$logo     = '';
		$login_bg = '';

		if ( $pro_active ) {
			$wl       = (array) get_option( 'jetonomy_pro_white_label', array() );
			$accent   = (string) ( $wl['accent_color'] ?? '' );
			$logo     = (string) ( $wl['logo_url'] ?? ( $wl['header_logo_url'] ?? '' ) );
			$login_bg = (string) ( $wl['login_bg_url'] ?? '' );
		}

		if ( '' === $accent ) {
			// Free Appearance tab default (Settings → Appearance → Accent).
			$accent = (string) ( $settings['accent_color'] ?? '#0073aa' );
		}

		if ( '' === $logo ) {
			// Free Appearance tab logo (Settings → Appearance → Logo). Pro
			// white-label, when set, overrides this above.
			$logo = (string) ( $settings['logo_url'] ?? '' );
		}

		return array(
			'accent_color' => $accent,
			'logo_url'     => $logo,
			'login_bg_url' => $login_bg,
		);
	}

	/**
	 * Terms of Service URL (Settings → General → Community Setup). No core WP
	 * fallback exists for this, so an unconfigured site returns ''.
	 *
	 * @param array $settings The `jetonomy_settings` option.
	 * @return string Always a string; '' means not configured.
	 */
	private function terms_url( array $settings ): string {
		return esc_url_raw( (string) ( $settings['terms_url'] ?? '' ) );
	}

	/**
	 * Privacy Policy URL (Settings → General → Community Setup), falling back
	 * to WordPress' own Privacy Policy page — most sites already set that, so
	 * this avoids making them re-enter the same URL for the app.
	 *
	 * @param array $settings The `jetonomy_settings` option.
	 * @return string Always a string; '' means not configured.
	 */
	private function privacy_url( array $settings ): string {
		$url = (string) ( $settings['privacy_url'] ?? '' );
		if ( '' === $url ) {
			$url = (string) get_privacy_policy_url();
		}
		return esc_url_raw( $url );
	}

	/**
	 * Map the enabled Pro extension IDs, plus free-only capabilities, to the
	 * app's feature flag block.
	 *
	 * Native push ships inside the web-push extension, so `native_push` gates
	 * on the same `web-push` ID as `web_push`. `anonymous-posting` and
	 * `attachments` are ordinary Pro extension IDs read the same way. `blocking`
	 * is a FREE capability (not a Pro extension) with no admin toggle, so it's
	 * hardcoded true rather than derived from the extensions option.
	 *
	 * @param array $settings The `jetonomy_settings` option (reserved for a
	 *                        future free-side feature toggle; unused today —
	 *                        every current flag derives from the extensions
	 *                        option or is a hardcoded free capability).
	 * @return array<string,bool>
	 */
	private function feature_flags( array $settings ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedFunctionParameter
		$ext = (array) get_option( 'jetonomy_pro_extensions', array() );
		$has = static function ( string $id ) use ( $ext ): bool {
			return in_array( $id, $ext, true );
		};

		return array(
			'messaging'     => $has( 'private-messaging' ),
			'reactions'     => $has( 'reactions' ),
			'polls'         => $has( 'polls' ),
			'badges'        => $has( 'custom-badges' ),
			'custom_fields' => $has( 'custom-fields' ),
			'web_push'      => $has( 'web-push' ),
			'native_push'   => $has( 'web-push' ),
			'anonymous'     => $has( 'anonymous-posting' ),
			'attachments'   => $has( 'attachments' ),
			// Free user-blocking feature (BlockedUser model + /users/me/blocks
			// routes), always available in 1.7.1+. No admin toggle exists.
			'blocking'      => true,
		);
	}
}
