<?php
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
			'name'         => 'New User',
			'requirements' => [],
			'rate_limits'  => [
				'create_posts'   => 3,
				'create_replies' => 10,
				'vote'           => 5,
			],
			'abilities'     => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag' ],
			'restrictions'  => [ 'rate_limited', 'no_upload_media', 'no_create_spaces' ],
		],
		1 => [
			'name'         => 'Basic',
			'requirements' => [
				'posts'            => 5,
				'days_active'      => 3,
				'replies_received' => 10,
			],
			'rate_limits'   => [],
			'abilities'     => [ 'upload_media', 'edit_own_posts', 'delete_own_posts' ],
			'restrictions'  => [ 'no_create_spaces' ],
		],
		2 => [
			'name'         => 'Member',
			'requirements' => [
				'posts'       => 30,
				'days_active' => 20,
				'reputation'  => 50,
			],
			'rate_limits'   => [],
			'abilities'     => [ 'create_spaces', 'join_spaces' ],
			'restrictions'  => [],
		],
		3 => [
			'name'         => 'Regular',
			'requirements' => [
				'posts'       => 100,
				'days_active' => 60,
				'reputation'  => 200,
			],
			'rate_limits'   => [],
			'abilities'     => [ 'recategorize_posts', 'rename_topics' ],
			'restrictions'  => [],
		],
		4 => [
			'name'         => 'Leader',
			'requirements' => [], // Manually granted.
			'rate_limits'   => [],
			'abilities'     => [ 'moderate', 'manage_users' ],
			'restrictions'  => [],
		],
		5 => [
			'name'         => 'Staff',
			'requirements' => [], // Manually granted.
			'rate_limits'   => [],
			'abilities'     => [ 'manage_settings', 'manage_categories', 'view_analytics' ],
			'restrictions'  => [],
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
	 * Return the human-readable name for a trust level.
	 *
	 * @param int $level Trust level (0–5).
	 * @return string Level name, or empty string if the level does not exist.
	 */
	public static function name( int $level ): string {
		return self::LEVELS[ $level ]['name'] ?? '';
	}

	/**
	 * Determine whether a trust level can be earned automatically.
	 *
	 * Levels 0–3 are auto-earned; levels 4–5 must be manually granted.
	 *
	 * @param int $level Trust level.
	 * @return bool
	 */
	public static function can_auto_earn( int $level ): bool {
		return $level >= 0 && $level <= 3;
	}
}
