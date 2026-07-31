<?php
/**
 * App-connect wiring — how the Jetonomy mobile app acquires its credential.
 *
 * Implements the Wbcom App Auth standard (docs/standards/app-auth.md; the
 * concrete rollout plan lives on the BN Pro shelf). The credential is always a
 * WP core Application Password; this class owns the two site-level questions
 * around acquiring one:
 *
 *   WHICH DOOR? One bridge per site. Around 60% of customer sites run
 *   BuddyNext alongside Jetonomy, and BuddyNext owns site auth there (its
 *   login carries the site's social providers and two-factor). So when BN is
 *   active, bridge_info() points the app at BN's connect bridge and Jetonomy
 *   registers its scheme into BN's allowlist — the Jetonomy app walks through
 *   the site's real front door and inherits everything behind it. Standalone,
 *   Jetonomy's own bridge (when built) is the door. The app never contains
 *   any of this logic: it reads /app/config and goes where it is pointed.
 *
 *   WHICH SCHEMES? The custom URL schemes this site may hand a credential to.
 *   `jetonomyapp` is Jetonomy's own; siblings join through the
 *   `jetonomy_app_connect_schemes` filter, mirroring the seam BuddyNext
 *   exposes for us.
 *
 * @package Jetonomy\Integrations
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

class App_Connect {

	/**
	 * The Jetonomy app's custom URL scheme (matches the app's app.json).
	 */
	private const SCHEME = 'jetonomyapp';

	/**
	 * Hook the cross-plugin seams. Instantiated unconditionally at boot —
	 * the BuddyNext filter is harmless when BN is absent, and registering it
	 * unconditionally is what makes activation ORDER irrelevant.
	 */
	public function __construct() {
		// Join BN's scheme allowlist so a Jetonomy-app connection through
		// BN's bridge may deliver the credential to jetonomyapp://.
		add_filter( 'buddynext_app_connect_schemes', array( $this, 'join_buddynext_allowlist' ) );
	}

	/**
	 * Register the Jetonomy app's scheme with BuddyNext's connect bridge.
	 *
	 * @param mixed $schemes Schemes BN will hand credentials to — a filter
	 *                       value, so guarded rather than trusted.
	 * @return array
	 */
	public function join_buddynext_allowlist( $schemes ): array {
		$schemes   = is_array( $schemes ) ? $schemes : array();
		$schemes[] = self::SCHEME;

		return array_values( array_unique( $schemes ) );
	}

	/**
	 * The schemes THIS site's Jetonomy bridge may hand a credential to.
	 *
	 * @return string[]
	 */
	public static function schemes(): array {
		/**
		 * Filter the custom URL schemes the Jetonomy app-connect flow may
		 * deliver a credential to. Every scheme here can RECEIVE an
		 * Application Password — add a scheme only for an app you ship,
		 * never a wildcard.
		 *
		 * @since 1.8.2
		 *
		 * @param string[] $schemes Allowed schemes.
		 */
		$schemes = (array) apply_filters( 'jetonomy_app_connect_schemes', array( self::SCHEME ) );

		return array_values( array_filter( array_map( 'strval', $schemes ) ) );
	}

	/**
	 * Which bridge serves this site, per the one-door rule.
	 *
	 * @return array{owner: string, connect_url: string, connect_schemes: string[]}
	 */
	public static function bridge_info(): array {
		if ( class_exists( '\\BuddyNext\\App\\AppConnectService' ) ) {
			$info = array(
				'owner'           => 'buddynext',
				'connect_url'     => (string) \BuddyNext\App\AppConnectService::connect_url(),
				'connect_schemes' => (array) \BuddyNext\App\AppConnectService::schemes(),
			);
		} else {
			$info = array(
				'owner'           => 'jetonomy',
				// Jetonomy's own bridge route lands with phase J3 of the
				// rollout plan; until then a standalone site reports no
				// bridge and the app uses its legacy authorize flow.
				'connect_url'     => '',
				'connect_schemes' => self::schemes(),
			);
		}

		/**
		 * Filter the resolved app-connect bridge for this site.
		 *
		 * Tests use it to simulate the BuddyNext-active path; a site with an
		 * unusual auth topology can point the app at its own door.
		 *
		 * @since 1.8.2
		 *
		 * @param array $info { owner, connect_url, connect_schemes }.
		 */
		return (array) apply_filters( 'jetonomy_app_connect_bridge', $info );
	}

	/**
	 * The `auth` block for GET /jetonomy/v1/app/config.
	 *
	 * Shape is the App Auth standard's, byte-compatible with BuddyNext's, so
	 * ONE app-side client parses every Wbcom product. social_providers is
	 * always present and always empty — Jetonomy has no social system of its
	 * own; on combined sites the app inherits BN's providers by walking BN's
	 * bridge, and the app discovers those from BN, not from us.
	 *
	 * @return array<string, mixed>
	 */
	public static function auth_block(): array {
		$bridge = self::bridge_info();

		return array(
			'social_providers'        => array(),
			'twofactor'               => false,
			'register'                => (bool) get_option( 'users_can_register' ),
			'app_passwords_available' => wp_is_application_passwords_available(),
			'connect_url'             => (string) $bridge['connect_url'],
			'connect_schemes'         => (array) $bridge['connect_schemes'],
		);
	}
}
