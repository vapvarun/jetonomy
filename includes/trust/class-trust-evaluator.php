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
	 *   post_count       (int) Topics plus replies the user has written.
	 *   days_active      (int) Number of distinct days the user has been active.
	 *   reputation       (int) Current reputation score.
	 *   replies_received (int) Total replies the user's posts have received.
	 *
	 * @param array $stats User activity stats.
	 * @return int Resolved trust level (0–3).
	 */
	public static function evaluate_level( array $stats ): int {
		$level = 0;

		// Each level needs all four thresholds the Permissions screen shows. A threshold left at 0 is
		// not checked, so an owner turns one off by clearing it. A member must clear the levels in
		// order: Level 2 is only tried after Level 1 is met.
		for ( $candidate = 1; $candidate <= 3; $candidate++ ) {
			if ( ! self::meets( $stats, Trust_Levels::get_requirements( $candidate ) ) ) {
				break;
			}
			$level = $candidate;
		}

		return $level;
	}

	/**
	 * Whether a member's stats meet one level's thresholds.
	 *
	 * @param array $stats        Same keys as evaluate_level().
	 * @param array $requirement  Thresholds: posts, days_active, reputation, replies_received.
	 * @return bool
	 */
	private static function meets( array $stats, array $requirement ): bool {
		return (int) ( $stats['post_count'] ?? 0 ) >= (int) ( $requirement['posts'] ?? 0 )
			&& (int) ( $stats['days_active'] ?? 0 ) >= (int) ( $requirement['days_active'] ?? 0 )
			&& (int) ( $stats['reputation'] ?? 0 ) >= (int) ( $requirement['reputation'] ?? 0 )
			&& (int) ( $stats['replies_received'] ?? 0 ) >= (int) ( $requirement['replies_received'] ?? 0 );
	}
}
