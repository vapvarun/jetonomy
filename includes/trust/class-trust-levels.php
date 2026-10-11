<?php
/**
 * Trust level definitions.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

/**
 * Trust level definitions for Jetonomy.
 *
 * Levels 0–3 can be earned automatically through usage. Levels 4–5 are
 * reserved for manually granted roles (staff, VIP, etc.).
 */
class Trust_Levels {

	/**
	 * Full trust level configuration.
	 *
	 * Keys per level:
	 *   name          - Human-readable label.
	 *   requirements  - Stats thresholds that must ALL be met for auto-promotion.
	 *   rate_limits   - Override limits applied at this level (empty = no limits).
	 *   abilities     - What reaching this level changes, and ONLY what the
	 *                   engine enforces: the rate-limit lift (Rate_Limiter) and
	 *                   the trust floors in Permission_Engine::can(). The
	 *                   Permissions tab prints these, so an entry nothing
	 *                   enforces promises owners a power members never get
	 */
	private const LEVELS = [
		0 => [
			'name'         => 'Newcomer',
			'requirements' => [],
			'rate_limits'  => [
				'create_posts'   => 3,
				'create_replies' => 10,
				'vote'           => 5,
			],
			'abilities'    => [],
		],
		1 => [
			'name'         => 'Member',
			'requirements' => [
				'posts'            => 5,
				'days_active'      => 3,
				'replies_received' => 10,
			],
			'rate_limits'  => [],
			'abilities'    => [ 'no_rate_limits' ],
		],
		2 => [
			'name'         => 'Regular',
			'requirements' => [
				'posts'       => 30,
				'days_active' => 20,
				'reputation'  => 50,
			],
			'rate_limits'  => [],
			// Not create_spaces: creating a space needs the jetonomy_create_spaces
			// capability plus trust level 4 (Permission_Engine), so listing it here
			// told owners Regular members could do it.
			'abilities'    => [],
		],
		3 => [
			'name'         => 'Trusted',
			'requirements' => [
				'posts'       => 100,
				'days_active' => 60,
				'reputation'  => 200,
			],
			'rate_limits'  => [],
			'abilities'    => [ 'edit_others_posts', 'move_posts', 'close_posts', 'pin_posts' ],
		],
		4 => [
			'name'         => 'Leader',
			'requirements' => [], // Manually granted.
			'rate_limits'  => [],
			'abilities'    => [ 'create_spaces' ],
		],
		5 => [
			'name'         => 'Moderator',
			'requirements' => [], // Manually granted.
			'rate_limits'  => [],
			'abilities'    => [],
		],
	];

	/**
	 * Return the full configuration array for a trust level.
	 *
	 * @param int $level Trust level (0–5).
	 * @return array Level config, or empty array if the level does not exist.
	 */
	public static function get( int $level ): array {
		return self::LEVELS[ $level ] ?? [];
	}

	/**
	 * Return the built-in default promotion thresholds for levels 1–3.
	 *
	 * Single source of truth consumed by the runtime reader, the admin
	 * sanitizer/view, and the activation seeder.
	 *
	 * @return array<int,array<string,int>>
	 */
	public static function defaults(): array {
		return [
			1 => [
				'posts'            => 5,
				'days_active'      => 3,
				'reputation'       => 0,
				'replies_received' => 10,
			],
			2 => [
				'posts'            => 30,
				'days_active'      => 20,
				'reputation'       => 50,
				'replies_received' => 0,
			],
			3 => [
				'posts'            => 100,
				'days_active'      => 60,
				'reputation'       => 200,
				'replies_received' => 0,
			],
		];
	}

	/**
	 * Return the promotion requirements for a trust level, merging admin-
	 * configured thresholds over the built-in defaults.
	 *
	 * @param int $level Trust level (1–3).
	 * @return array Threshold key/value pairs, or empty array if not applicable.
	 */
	public static function get_requirements( int $level ): array {
		$settings   = get_option( 'jetonomy_settings', [] );
		$thresholds = $settings['trust_thresholds'] ?? [];
		$defaults   = self::defaults();

		return $thresholds[ $level ] ?? $defaults[ $level ] ?? [];
	}

	/**
	 * Return the human-readable name for a trust level.
	 *
	 * @param int $level Trust level (0–5).
	 * @return string Level name, or empty string if the level does not exist.
	 */
	public static function name( int $level ): string {
		return self::LEVELS[ $level ]['name'] ?? '';
	}

	/**
	 * Translated display name for a trust level.
	 *
	 * THE single source of truth for what a member sees. The admin Users
	 * screen, the promotion email and the Permissions tab all read it, so a
	 * member promoted to a level sees the same name everywhere.
	 *
	 * Separate from name() on purpose. name() returns the canonical untranslated
	 * string and is what `trust_level_name` carries in REST payloads and CLI
	 * journeys - translating that would make an API value vary with the admin's
	 * locale and break any client comparing it. label() is display-only.
	 *
	 * @param int $level Trust level (0-5).
	 * @return string Translated name, or empty string for an unknown level.
	 */
	public static function label( int $level ): string {
		$labels = array(
			0 => __( 'Newcomer', 'jetonomy' ),
			1 => __( 'Member', 'jetonomy' ),
			2 => __( 'Regular', 'jetonomy' ),
			3 => __( 'Trusted', 'jetonomy' ),
			4 => __( 'Leader', 'jetonomy' ),
			5 => __( 'Moderator', 'jetonomy' ),
		);

		/**
		 * Filter the display name of a trust level.
		 *
		 * Lets a site rename the ladder (e.g. back to New/Basic/.../Elder)
		 * without forking a template, and keeps every surface in step because
		 * they all read through here.
		 *
		 * @since 1.9.3
		 *
		 * @param string $label The translated level name.
		 * @param int    $level Trust level (0-5).
		 */
		return (string) apply_filters( 'jetonomy_trust_level_label', $labels[ $level ] ?? '', $level );
	}
}
