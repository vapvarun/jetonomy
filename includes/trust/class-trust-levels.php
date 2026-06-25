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
}
