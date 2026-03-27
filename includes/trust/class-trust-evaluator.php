<?php
/**
 * Trust level evaluator.
 *
 * @package Jetonomy
 */

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
		$post_count       = (int) ( $stats['post_count'] ?? 0 );
		$days_active      = (int) ( $stats['days_active'] ?? 0 );
		$reputation       = (int) ( $stats['reputation'] ?? 0 );
		$replies_received = (int) ( $stats['replies_received'] ?? 0 );

		$level = 0;

		// Level 1 requirements (admin-configurable).
		$req1 = Trust_Levels::get_requirements( 1 );
		if (
			$post_count >= ( $req1['posts'] ?? 5 ) &&
			$days_active >= ( $req1['days_active'] ?? 3 ) &&
			$replies_received >= ( $req1['replies_received'] ?? 10 )
		) {
			$level = 1;
		} else {
			return $level;
		}

		// Level 2 requirements (admin-configurable).
		$req2 = Trust_Levels::get_requirements( 2 );
		if (
			$post_count >= ( $req2['posts'] ?? 30 ) &&
			$days_active >= ( $req2['days_active'] ?? 20 ) &&
			$reputation >= ( $req2['reputation'] ?? 50 )
		) {
			$level = 2;
		} else {
			return $level;
		}

		// Level 3 requirements (admin-configurable).
		$req3 = Trust_Levels::get_requirements( 3 );
		if (
			$post_count >= ( $req3['posts'] ?? 100 ) &&
			$days_active >= ( $req3['days_active'] ?? 60 ) &&
			$reputation >= ( $req3['reputation'] ?? 200 )
		) {
			$level = 3;
		}

		return $level;
	}
}
