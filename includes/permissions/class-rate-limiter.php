<?php
namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user, per-action rate limiter backed by WordPress transients.
 *
 * Limits reset after 24 hours (DAY_IN_SECONDS). Trust Level 1+ users are
 * exempt from all rate limits.
 */
class Rate_Limiter {

	/**
	 * Check whether a user is below the rate limit for an action.
	 *
	 * @param int    $user_id     WP user ID.
	 * @param string $action      Action key (e.g. 'create_posts').
	 * @param int    $trust_level User's current trust level.
	 * @return bool True if the action is allowed, false if the limit is reached.
	 */
	public static function check( int $user_id, string $action, int $trust_level ): bool {
		$limits = self::get_limits( $trust_level );
		if ( ! isset( $limits[ $action ] ) ) {
			return true; // No limit defined for this action.
		}

		$key   = "jetonomy_rate_{$user_id}_{$action}";
		$count = (int) get_transient( $key );

		return $count < $limits[ $action ];
	}

	/**
	 * Increment the usage counter for a user/action pair.
	 *
	 * Should be called after a rate-limited action is successfully performed.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $action  Action key.
	 */
	public static function increment( int $user_id, string $action ): void {
		$key   = "jetonomy_rate_{$user_id}_{$action}";
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
	}

	/**
	 * Return the rate-limit map for a given trust level.
	 *
	 * Trust Level 1+ users have no rate limits. Level 0 users are restricted
	 * on high-volume actions to reduce spam.
	 *
	 * @param int $trust_level
	 * @return array<string,int> Map of action => max-per-day (empty = no limits).
	 */
	private static function get_limits( int $trust_level ): array {
		if ( $trust_level >= 1 ) {
			return []; // No rate limits for Level 1+.
		}

		$settings = get_option( 'jetonomy_settings', [] );
		$limits   = $settings['rate_limits'] ?? [];

		return [
			'create_posts'   => (int) ( $limits['posts']   ?? 3  ),
			'create_replies' => (int) ( $limits['replies']  ?? 10 ),
			'vote'           => (int) ( $limits['votes']    ?? 5  ),
		];
	}
}
