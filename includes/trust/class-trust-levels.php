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
	 *   abilities     - Additional actions unlocked at this level.
	 *   restrictions  - Constraints still in place at this level.
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
			'abilities'    => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag' ],
			'restrictions' => [ 'rate_limited', 'no_upload_media', 'no_create_spaces' ],
		],
		1 => [
			'name'         => 'Member',
			'requirements' => [
				'posts'            => 5,
				'days_active'      => 3,
				'replies_received' => 10,
			],
			'rate_limits'  => [],
			'abilities'    => [ 'upload_media', 'edit_own_posts', 'delete_own_posts' ],
			'restrictions' => [ 'no_create_spaces' ],
		],
		2 => [
			'name'         => 'Regular',
			'requirements' => [
				'posts'       => 30,
				'days_active' => 20,
				'reputation'  => 50,
			],
			'rate_limits'  => [],
			'abilities'    => [ 'create_spaces', 'join_spaces' ],
			'restrictions' => [],
		],
		3 => [
			'name'         => 'Trusted',
			'requirements' => [
				'posts'       => 100,
				'days_active' => 60,
				'reputation'  => 200,
			],
			'rate_limits'  => [],
			'abilities'    => [ 'recategorize_posts', 'rename_topics' ],
			'restrictions' => [],
		],
		4 => [
			'name'         => 'Leader',
			'requirements' => [], // Manually granted.
			'rate_limits'  => [],
			'abilities'    => [ 'moderate', 'manage_users' ],
			'restrictions' => [],
		],
		5 => [
			'name'         => 'Moderator',
			'requirements' => [], // Manually granted.
			'rate_limits'  => [],
			'abilities'    => [ 'manage_settings', 'manage_categories', 'view_analytics' ],
			'restrictions' => [],
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
	 * THE single source of truth for what a member sees. Three surfaces used to
	 * carry their own hardcoded list and had drifted a full level apart
	 * (Basecamp 10210059424): the admin Users screen called level 1 "Basic",
	 * level 2 "Member" and level 5 "Elder", while the promotion email and the
	 * Permissions tab used the LEVELS names. A member promoted to level 2 got
	 * an email saying "Regular" and appeared on the Users screen as "Member".
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
