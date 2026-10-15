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
	 *                   engine enforces: the rate-limit lift (Rate_Limiter),
	 *                   the CAPTCHA skip (Captcha_Manager, via unlocked()) and
	 *                   the trust floors in Permission_Engine::can(). The
	 *                   Permissions tab prints these, so an entry nothing
	 *                   enforces promises owners a power members never get
	 */
	private const LEVELS = array(
		0 => array(
			'name'         => 'Newcomer',
			'requirements' => array(),
			'rate_limits'  => array(
				'create_posts'   => 3,
				'create_replies' => 10,
				'vote'           => 5,
			),
			'abilities'    => array(),
		),
		1 => array(
			'name'         => 'Member',
			'requirements' => array(
				'posts'            => 5,
				'days_active'      => 3,
				'replies_received' => 10,
			),
			'rate_limits'  => array(),
			'abilities'    => array( 'no_rate_limits' ),
		),
		2 => array(
			'name'         => 'Regular',
			'requirements' => array(
				'posts'       => 30,
				'days_active' => 20,
				'reputation'  => 50,
			),
			'rate_limits'  => array(),
			'abilities'    => array( 'skip_captcha' ),
		),
		3 => array(
			'name'         => 'Trusted',
			'requirements' => array(
				'posts'       => 100,
				'days_active' => 60,
				'reputation'  => 200,
			),
			'rate_limits'  => array(),
			'abilities'    => array( 'edit_others_posts', 'delete_others_posts', 'move_posts', 'close_posts', 'pin_posts' ),
		),
		4 => array(
			'name'         => 'Leader',
			'requirements' => array(), // Manually granted.
			'rate_limits'  => array(),
			'abilities'    => array(),
		),
		5 => array(
			'name'         => 'Moderator',
			'requirements' => array(), // Manually granted.
			'rate_limits'  => array(),
			'abilities'    => array(),
		),
	);

	/**
	 * Whether reaching $level (or any level below it) unlocks $ability.
	 *
	 * @param int    $level   Trust level.
	 * @param string $ability Ability key from LEVELS.
	 * @return bool
	 */
	public static function unlocked( int $level, string $ability ): bool {
		$top = min( 5, $level );
		for ( $l = 0; $l <= $top; $l++ ) {
			if ( in_array( $ability, self::LEVELS[ $l ]['abilities'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the full configuration array for a trust level.
	 *
	 * @param int $level Trust level (0–5).
	 * @return array Level config, or empty array if the level does not exist.
	 */
	public static function get( int $level ): array {
		return self::LEVELS[ $level ] ?? array();
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
		return array(
			1 => array(
				'posts'            => 5,
				'days_active'      => 3,
				'reputation'       => 0,
				'replies_received' => 10,
			),
			2 => array(
				'posts'            => 30,
				'days_active'      => 20,
				'reputation'       => 50,
				'replies_received' => 0,
			),
			3 => array(
				'posts'            => 100,
				'days_active'      => 60,
				'reputation'       => 200,
				'replies_received' => 0,
			),
		);
	}

	/**
	 * Return the promotion requirements for a trust level, merging admin-
	 * configured thresholds over the built-in defaults.
	 *
	 * @param int $level Trust level (1–3).
	 * @return array Threshold key/value pairs, or empty array if not applicable.
	 */
	public static function get_requirements( int $level ): array {
		$settings   = get_option( 'jetonomy_settings', array() );
		$thresholds = $settings['trust_thresholds'] ?? array();
		$defaults   = self::defaults();

		return $thresholds[ $level ] ?? $defaults[ $level ] ?? array();
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

	/**
	 * Set a member's trust level by hand - the one write path for wp-admin and REST.
	 *
	 * Trust unlocks capabilities held behind the trust floor, so a delegated
	 * "Manage community users" holder must not raise themselves or reach above
	 * their rank: only an administrator may change their own level, another
	 * administrator's, or a moderator's. Same rules as Restriction::actor_may_restrict().
	 *
	 * @param int $user_id  Member whose level changes.
	 * @param int $level    New level, clamped to 0-5.
	 * @param int $actor_id User making the change.
	 * @return int|\WP_Error The level now stored, or why the change was refused.
	 */
	public static function set_manually( int $user_id, int $level, int $actor_id ): int|\WP_Error {
		$level = max( 0, min( 5, $level ) );

		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			if ( $user_id === $actor_id ) {
				return new \WP_Error( 'jetonomy_trust_self', __( 'You cannot change your own trust level.', 'jetonomy' ), array( 'status' => 403 ) );
			}
			if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'jetonomy_moderate' ) ) {
				return new \WP_Error( 'jetonomy_trust_peer', __( 'Only an administrator can change the trust level of an administrator or moderator.', 'jetonomy' ), array( 'status' => 403 ) );
			}
		}

		\Jetonomy\Models\UserProfile::find_or_create( $user_id );
		$old = (int) ( \Jetonomy\Models\UserProfile::find_by_user( $user_id )->trust_level ?? 0 );
		if ( $old === $level ) {
			return $level;
		}
		if ( ! \Jetonomy\Models\UserProfile::update_profile( $user_id, array( 'trust_level' => $level ) ) ) {
			return new \WP_Error( 'jetonomy_trust_failed', __( 'Failed to update trust level.', 'jetonomy' ), array( 'status' => 500 ) );
		}
		do_action( 'jetonomy_trust_level_changed', $user_id, $old, $level );

		return $level;
	}
}
