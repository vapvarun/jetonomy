<?php
/**
 * Wbcom stack companion registry.
 *
 * A single declarative, filterable catalog of the Wbcom plugins Jetonomy pairs
 * with (WB Gamification, WPMediaVerse, Learnomy, …). Each entry is DATA, not
 * code - Pro and third parties extend the list via the `jetonomy_companions`
 * filter. Every UI + integration decision keys off `status()` / `is_active()`
 * (a runtime capability probe), never a hardcoded plugin path, so "works
 * standalone" and "no duplication" both hold: capability present -> delegate;
 * absent -> offer to install.
 *
 * Self-contained on purpose (mirrors Learnomy's includes/integrations/ wrapper):
 * the installer speaks the EDD delivery channel every Wbcom plugin already
 * bundles, so no external library or remote catalog is required.
 *
 * @package Jetonomy\Integrations
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

final class Companion_Registry {

	/**
	 * Resolve the companion catalog. Each entry:
	 *   label     string   Display name.
	 *   why       string   One-line value proposition.
	 *   detect    callable Returns true when the companion's capability is live.
	 *   free      array    { item_id, key, basename } for one-click free install.
	 *   store_url string   Product page for the "Learn more" / Pro link.
	 *   unlocks   string   What this turns on around a Jetonomy community.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Filter the Wbcom stack companion catalog. Pro + third-party plugins
		 * add their own entries here; the installer + admin screen render
		 * whatever this returns.
		 *
		 * @param array<string, array<string, mixed>> $companions Slug => entry.
		 */
		return (array) apply_filters(
			'jetonomy_companions',
			array(
				'wb-gamification' => array(
					'label'     => __( 'WB Gamification', 'jetonomy' ),
					'why'       => __( 'Points, badges, and leaderboards for posts, replies, and community activity.', 'jetonomy' ),
					'detect'    => static fn() => defined( 'WB_GAM_VERSION' ) || function_exists( 'wb_gam' ),
					'free'      => array(
						'item_id'  => 1662147,
						'key'      => 'wbcomfree6e2a9c1d7b4f3c8a0e5d9b2f1a7c6e11',
						'basename' => 'wb-gamification/wb-gamification.php',
					),
					'store_url' => 'https://wbcomdesigns.com',
					'unlocks'   => __( 'Gamified points and badges across your community.', 'jetonomy' ),
				),
				'wpmediaverse'    => array(
					'label'     => __( 'MediaVerse', 'jetonomy' ),
					'why'       => __( 'A social media layer - photo and video feeds alongside your community discussions.', 'jetonomy' ),
					'detect'    => static fn() => defined( 'MVS_VERSION' ) || function_exists( 'mvs' ),
					'free'      => array(
						'item_id'  => 1660826,
						'key'      => 'wbcomfree7a9c2e5d1f8b4c6a3e0d9b2f7c1a8e44',
						'basename' => 'wpmediaverse/wpmediaverse.php',
					),
					'store_url' => 'https://wbcomdesigns.com',
					'unlocks'   => __( 'Media-rich social posting next to your spaces.', 'jetonomy' ),
				),
				'buddynext'       => array(
					'label'     => __( 'BuddyNext', 'jetonomy' ),
					'why'       => __( 'Community engine - profiles, activity feeds, and member spaces.', 'jetonomy' ),
					'detect'    => static fn() => defined( 'BUDDYNEXT_VERSION' ),
					'free'      => array(
						'item_id'  => 1664401,
						'key'      => 'buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55',
						'basename' => 'buddynext/buddynext.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/buddynext/',
					'unlocks'   => __( 'Community feed + member profiles around your spaces.', 'jetonomy' ),
				),
				'learnomy'        => array(
					'label'     => __( 'Learnomy', 'jetonomy' ),
					'why'       => __( 'Sell and deliver online courses, then gate community spaces on course enrollment.', 'jetonomy' ),
					'detect'    => static fn() => defined( 'LEARNOMY_VERSION' ) || class_exists( '\\Learnomy\\Learnomy' ),
					'free'      => array(
						'item_id'  => 1662698,
						'key'      => 'wbcomfree5d8a1f3c7b2e9a4c6f0d1e8b3c9a7f25',
						'basename' => 'learnomy/learnomy.php',
					),
					'store_url' => 'https://wbcomdesigns.com',
					'unlocks'   => __( 'Course-gated spaces via the built-in Learnomy access adapter.', 'jetonomy' ),
				),
				'wp-career-board' => array(
					'label'     => __( 'WP Career Board', 'jetonomy' ),
					'why'       => __( 'Job listings and applicant management with employer profiles.', 'jetonomy' ),
					'detect'    => static fn() => defined( 'WCB_VERSION' ) || class_exists( '\\WCB\\Core\\Plugin' ),
					'free'      => array(
						'item_id'  => 1659888,
						'key'      => 'wbcomfree5b8c1e7a9d3f2a4c6e0d1b7f9c2a6e00',
						'basename' => 'wp-career-board/wp-career-board.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/wp-career-board/',
					'unlocks'   => __( 'Job posts surfaced as discussion topics.', 'jetonomy' ),
				),
				'wb-listora'      => array(
					'label'     => __( 'Listora', 'jetonomy' ),
					'why'       => __( 'Member-submitted directory listings.', 'jetonomy' ),
					'detect'    => static fn() => defined( 'WB_LISTORA_VERSION' ),
					'free'      => array(
						'item_id'  => 1662779,
						'key'      => 'wbcomfree8a5d1c7e3f2b9a4c6e0d1b7f9c2a6e55',
						'basename' => 'wb-listora/wb-listora.php',
					),
					'store_url' => 'https://wbcomdesigns.com/downloads/listora/',
					'unlocks'   => __( 'Member listings shared into your community.', 'jetonomy' ),
				),
			)
		);
	}

	/**
	 * A single companion entry, or null when the slug is unknown.
	 *
	 * @param string $slug Companion slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Lifecycle status of a companion:
	 *   'active'             - its capability probe returns true (installed + on).
	 *   'installed_inactive' - the plugin file is present but not active.
	 *   'not_installed'      - absent.
	 *
	 * @param string $slug Companion slug.
	 */
	public static function status( string $slug ): string {
		$entry = self::get( $slug );
		if ( null === $entry ) {
			return 'not_installed';
		}

		$detect = $entry['detect'] ?? null;
		if ( is_callable( $detect ) && (bool) $detect() ) {
			return 'active';
		}

		$basename = (string) ( $entry['free']['basename'] ?? '' );
		if ( '' !== $basename && self::plugin_file_exists( $basename ) ) {
			return 'installed_inactive';
		}

		return 'not_installed';
	}

	/**
	 * Whether a companion's capability is live. The single gate Jetonomy
	 * integration code should call before delegating to a companion.
	 *
	 * @param string $slug Companion slug.
	 */
	public static function is_active( string $slug ): bool {
		return 'active' === self::status( $slug );
	}

	/**
	 * Whether a plugin file exists under wp-content/plugins.
	 *
	 * @param string $basename e.g. "learnomy/learnomy.php".
	 */
	private static function plugin_file_exists( string $basename ): bool {
		$path = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $basename, '/' );
		return file_exists( $path );
	}
}
