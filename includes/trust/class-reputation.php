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
	 * Some entries are defined ahead of the callsites that will award them.
	 */
	private const POINTS_MAP = array(
		// Small participation award for publishing content. Quality signals
		// (upvotes/accepts) still dominate; keep these low so the rep economy
		// isn't gamed by volume. Owners can retune or zero them out via the
		// reputation_points setting / jetonomy_reputation_points_map filter.
		'post_created'    => 2,
		'reply_created'   => 1,
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
		/**
		 * Filter the entire reputation POINTS_MAP before per-action resolution.
		 *
		 * Use this to add new action keys or wholesale-replace the scoring table
		 * (e.g. per-community ladders). The `jetonomy_reputation_points_for`
		 * filter still runs afterwards on the resolved value for the requested
		 * action, so this filter and that one compose.
		 *
		 * @param array<string,int> $map Default POINTS_MAP keyed by action slug.
		 */
		$map     = (array) apply_filters( 'jetonomy_reputation_points_map', self::POINTS_MAP );
		$default = $map[ $action ] ?? 0;

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
	 * Return the hardcoded defaults so the Settings UI can show "Reset to default" affordances.
	 *
	 * @return array<string,int>
	 */
	public static function action_points_defaults(): array {
		return (array) apply_filters( 'jetonomy_reputation_points_map', self::POINTS_MAP );
	}

	/**
	 * Award (or deduct) reputation points for a user action.
	 *
	 * Looks up the action in POINTS_MAP, applies the delta via the internal
	 * UserProfile primitive, and fires `jetonomy_reputation_changed`.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $action  Action key from POINTS_MAP.
	 * @param array  $context The post or reply that earned it: object_type, object_id.
	 *                        Logs point at that content, so an anonymous author stays
	 *                        masked wherever the activity is shown.
	 * @return int The point delta that was applied (0 if action is unknown).
	 */
	public static function award( int $user_id, string $action, array $context = array() ): int {
		$delta = self::points_for( $action );

		if ( 0 === $delta ) {
			return 0;
		}

		return self::dispatch( $user_id, $delta, $action, $context );
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
	 * @param array  $context The post or reply concerned, as for award().
	 * @return int The (negative or zero) delta that was applied.
	 */
	public static function revoke( int $user_id, string $action, array $context = array() ): int {
		$original = self::points_for( $action );

		if ( 0 === $original ) {
			return 0;
		}

		$delta = -$original;

		return self::dispatch( $user_id, $delta, $action . '_revoked', $context );
	}

	/**
	 * Award $action for this object unless an earlier award is still
	 * outstanding (one per object, however often the triggering state repeats).
	 *
	 * @param int    $user_id Member.
	 * @param string $action  POINTS_MAP key.
	 * @param array  $context Must carry object_type + object_id.
	 * @return int Delta applied.
	 */
	public static function award_once( int $user_id, string $action, array $context ): int {
		// Claim the object first: add_option() is atomic (unique option_name), so
		// a double-clicked moderator action cannot pass the outstanding check
		// twice before the first award is logged.
		$lock = '_jt_rep_lock_' . md5( $action . '|' . $user_id . '|' . ( $context['object_type'] ?? '' ) . '|' . ( $context['object_id'] ?? 0 ) );
		if ( ! add_option( $lock, time(), '', false ) ) {
			return 0;
		}
		try {
			return self::outstanding( $user_id, $action, $context ) > 0 ? 0 : self::award( $user_id, $action, $context );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Give back $action for this object only if the ledger shows it was applied
	 * and not yet given back - never return points that were never taken (or
	 * take back points that were never given).
	 *
	 * @param int    $user_id Member.
	 * @param string $action  POINTS_MAP key.
	 * @param array  $context Must carry object_type + object_id.
	 * @return int Delta applied (0 when nothing was outstanding).
	 */
	public static function revoke_awarded( int $user_id, string $action, array $context ): int {
		if ( self::outstanding( $user_id, $action, $context ) <= 0 ) {
			return 0;
		}
		return self::revoke( $user_id, $action, $context );
	}

	/**
	 * Net applications of $action on the context object for $user_id.
	 *
	 * @param int    $user_id Member.
	 * @param string $action  POINTS_MAP key.
	 * @param array  $context object_type + object_id.
	 * @return int
	 */
	private static function outstanding( int $user_id, string $action, array $context ): int {
		return \Jetonomy\Models\ActivityLog::reputation_net( $user_id, $action, (string) ( $context['object_type'] ?? '' ), (int) ( $context['object_id'] ?? 0 ) );
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
		/**
		 * Filter the reputation delta immediately before it is persisted.
		 *
		 * Use this to scale deltas during campaigns ("double points weekend"),
		 * veto for sandboxed users (return 0), or redirect rep to an external
		 * scoring engine. Fires before `UserProfile::_apply_reputation_delta`
		 * so a 0 return prevents the write entirely.
		 *
		 * @param int    $delta   Signed point delta about to be applied.
		 * @param int    $user_id WP user ID whose reputation will change.
		 * @param string $action  Action key (or `<action>_revoked` for revocations).
		 * @param array  $context Optional context payload (e.g. badge_id).
		 */
		$delta = (int) apply_filters( 'jetonomy_reputation_pre_change', $delta, $user_id, $action, $context );

		if ( 0 === $delta ) {
			return 0;
		}

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
