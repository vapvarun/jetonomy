<?php
namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the appropriate trust level for a user based on their activity stats.
 *
 * Only levels 0–3 are evaluated here; levels 4–5 must be granted manually and
 * are never returned by this class.
 */
class Trust_Evaluator {

	/**
	 * Evaluate the highest auto-earnable trust level a user qualifies for.
	 *
	 * Checks requirements for levels 1, 2, and 3 in sequence. Returns the
	 * highest level whose requirements are fully met. If no requirements are
	 * met, returns level 0 (default / new user).
	 *
	 * Expected stats array keys:
	 *   post_count       (int) Total posts created by the user.
	 *   days_active      (int) Number of distinct days the user has been active.
	 *   reputation       (int) Current reputation score.
	 *   replies_received (int) Total replies the user's posts have received.
	 *
	 * @param array $stats User activity stats.
	 * @return int Resolved trust level (0–3).
	 */
	public static function evaluate_level( array $stats ): int {
		$post_count       = (int) ( $stats['post_count']       ?? 0 );
		$days_active      = (int) ( $stats['days_active']      ?? 0 );
		$reputation       = (int) ( $stats['reputation']       ?? 0 );
		$replies_received = (int) ( $stats['replies_received'] ?? 0 );

		$level = 0;

		// Level 1: posts >= 5, days_active >= 3, replies_received >= 10.
		if ( $post_count >= 5 && $days_active >= 3 && $replies_received >= 10 ) {
			$level = 1;
		} else {
			return $level;
		}

		// Level 2: posts >= 30, days_active >= 20, reputation >= 50.
		if ( $post_count >= 30 && $days_active >= 20 && $reputation >= 50 ) {
			$level = 2;
		} else {
			return $level;
		}

		// Level 3: posts >= 100, days_active >= 60, reputation >= 200.
		if ( $post_count >= 100 && $days_active >= 60 && $reputation >= 200 ) {
			$level = 3;
		}

		return $level;
	}
}
