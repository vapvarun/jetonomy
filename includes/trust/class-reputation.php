<?php
/**
 * Reputation calculator.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\UserProfile;

/**
 * Reputation point calculator and public award/revoke facade.
 *
 * This is the ONLY public mutator for user reputation. Direct calls to
 * `UserProfile::_apply_reputation_delta()` from outside this class are
 * disallowed — they bypass the `jetonomy_reputation_changed` action and
 * the centralized POINTS_MAP.
 *
 * Point values follow the forum's engagement model: positive actions reward
 * participation, while negative actions (flags, removals) reduce reputation.
 */
class Reputation {

	/**
	 * Points awarded (or deducted) per action type.
	 *
	 * Keys are stable identifiers passed to {@see award()} / {@see revoke()}.
	 * Some entries are populated for use by WS4-B (currently un-wired callsites).
	 */
	private const POINTS_MAP = array(
		'post_upvoted'    => 10,
		'reply_upvoted'   => 5,
		'post_downvoted'  => -2,
		'reply_downvoted' => -2,
		'reply_accepted'  => 15,
		'idea_planned'    => 20,
		'flag_validated'  => 5,
		'post_reported'   => -10,
		'post_removed'    => -20,

		// Legacy/generic alias retained for any caller that has not yet
		// migrated to the discriminated post_/reply_ keys.
		'downvoted'       => -2,
	);

	/**
	 * Return the point value for a given action.
	 *
	 * Lookup order: per-site admin override (jetonomy_settings.reputation_points)
	 * → hardcoded POINTS_MAP → 0. Admins can adjust every value on the
	 * Settings → Reputation surface without code edits; the hardcoded map is
	 * the safety net so a site with no saved override still scores correctly.
	 *
	 * Filter `jetonomy_reputation_points_for` lets integrations override the
	 * resolved value for a specific action (passed alongside the action key
	 * so listeners can branch on it).
	 *
	 * @param string $action Action key from POINTS_MAP.
	 * @return int
	 */
	public static function points_for( string $action ): int {
		$default = self::POINTS_MAP[ $action ] ?? 0;

		$settings  = get_option( 'jetonomy_settings', array() );
		$overrides = is_array( $settings ) && isset( $settings['reputation_points'] ) && is_array( $settings['reputation_points'] )
			? $settings['reputation_points']
			: array();
		$points    = array_key_exists( $action, $overrides ) ? (int) $overrides[ $action ] : $default;

		/**
		 * Filter the resolved reputation points for an action.
		 *
		 * Runs after the admin override + hardcoded fallback resolve, so
		 * filter listeners see the final number that would apply.
		 *
		 * @param int    $points Resolved point delta.
		 * @param string $action Action key being scored.
		 */
		return (int) apply_filters( 'jetonomy_reputation_points_for', $points, $action );
	}

	/**
	 * Return every action key with its current point value.
	 *
	 * Used by the Settings admin UI so the renderer never has to peek at the
	 * private POINTS_MAP constant. Keys are the canonical action identifiers;
	 * values reflect any per-site override that has already been applied.
	 *
	 * @return array<string,int>
	 */
	public static function action_points_map(): array {
		$map = array();
		foreach ( self::POINTS_MAP as $action => $unused ) {
			$map[ $action ] = self::points_for( $action );
		}
		return $map;
	}

	/**
	 * Return the hardcoded defaults so the Settings UI can show "Reset to default" affordances.
	 *
	 * @return array<string,int>
	 */
	public static function action_points_defaults(): array {
		return self::POINTS_MAP;
	}

	/**
	 * Award (or deduct) reputation points for a user action.
	 *
	 * Looks up the action in POINTS_MAP, applies the delta via the internal
	 * UserProfile primitive, and fires `jetonomy_reputation_changed`.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $action  Action key from POINTS_MAP.
	 * @return int The point delta that was applied (0 if action is unknown).
	 */
	public static function award( int $user_id, string $action ): int {
		$delta = self::points_for( $action );

		if ( 0 === $delta ) {
			return 0;
		}

		return self::dispatch( $user_id, $delta, $action, array() );
	}

	/**
	 * Revoke a previously-awarded action by applying the inverse delta.
	 *
	 * Used when a vote is retracted or an awarded action is undone. Fires
	 * `jetonomy_reputation_changed` with the action key suffixed `_revoked`
	 * so listeners can distinguish revocations from fresh awards.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $action  The original action key that was awarded.
	 * @return int The (negative or zero) delta that was applied.
	 */
	public static function revoke( int $user_id, string $action ): int {
		$original = self::points_for( $action );

		if ( 0 === $original ) {
			return 0;
		}

		$delta = -$original;

		return self::dispatch( $user_id, $delta, $action . '_revoked', array() );
	}

	/**
	 * Apply a custom delta not present in POINTS_MAP.
	 *
	 * Intended for callers that compute reputation dynamically (e.g. per-badge
	 * bonuses, admin overrides). Fires `jetonomy_reputation_changed` with the
	 * caller-supplied action key and context payload.
	 *
	 * @param int    $user_id WP user ID.
	 * @param int    $delta   Signed point delta to apply. Zero is a no-op.
	 * @param string $action  Free-form action key for logging/listeners.
	 * @param array  $context Extra context passed to the hook (e.g. badge_id).
	 * @return int The delta that was applied (0 if $delta was 0).
	 */
	public static function award_custom( int $user_id, int $delta, string $action, array $context = array() ): int {
		if ( 0 === $delta ) {
			return 0;
		}

		return self::dispatch( $user_id, $delta, $action, $context );
	}

	/**
	 * Internal: persist a delta and fire the public hook.
	 *
	 * @param int    $user_id WP user ID.
	 * @param int    $delta   Signed delta to persist.
	 * @param string $action  Action label for the hook.
	 * @param array  $context Extra hook payload.
	 * @return int $delta (echoed back for the public facade return value).
	 */
	private static function dispatch( int $user_id, int $delta, string $action, array $context ): int {
		UserProfile::_apply_reputation_delta( $user_id, $delta );

		/**
		 * Fires after a user's reputation has been adjusted.
		 *
		 * @param int    $user_id WP user ID whose reputation changed.
		 * @param string $action  The action that triggered the change. For
		 *                        revocations, this is `<original_action>_revoked`.
		 * @param int    $delta   Points added (positive) or removed (negative).
		 * @param array  $context Optional context payload supplied by callers
		 *                        of {@see award_custom()}. Empty array for
		 *                        award()/revoke().
		 */
		do_action( 'jetonomy_reputation_changed', $user_id, $action, $delta, $context );

		return $delta;
	}
}
