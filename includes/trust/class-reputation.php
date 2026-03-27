<?php
namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\UserProfile;

/**
 * Reputation point calculator and award helper.
 *
 * Point values follow the forum's engagement model: positive actions reward
 * participation, while negative actions (flags, removals) reduce reputation.
 */
class Reputation {

	/**
	 * Points awarded (or deducted) per action type.
	 */
	private const POINTS_MAP = [
		'post_upvoted'   => 10,
		'reply_upvoted'  => 5,
		'reply_accepted' => 15,
		'idea_planned'   => 20,
		'downvoted'      => -2,
		'flag_validated' => 5,
		'post_reported'  => -10,
		'post_removed'   => -20,
	];

	/**
	 * Return the point value for a given action.
	 *
	 * Returns 0 for unknown actions (no-op).
	 *
	 * @param string $action Action key from POINTS_MAP.
	 * @return int
	 */
	public static function points_for( string $action ): int {
		return self::POINTS_MAP[ $action ] ?? 0;
	}

	/**
	 * Award (or deduct) reputation points for a user action.
	 *
	 * Calculates the point delta, delegates persistence to UserProfile, and
	 * fires the `jetonomy_reputation_changed` action with the outcome.
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

		UserProfile::adjust_reputation( $user_id, $delta );

		/**
		 * Fires after a user's reputation has been adjusted.
		 *
		 * @param int    $user_id WP user ID whose reputation changed.
		 * @param string $action  The action that triggered the change.
		 * @param int    $delta   Points added (positive) or removed (negative).
		 */
		do_action( 'jetonomy_reputation_changed', $user_id, $action, $delta );

		return $delta;
	}
}
