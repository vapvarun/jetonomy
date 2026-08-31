<?php
/**
 * Rate limiter.
 *
 * @package Jetonomy
 */

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
		// Admins and moderators bypass all rate limits.
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'jetonomy_moderate' ) ) {
			return true;
		}

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
	 * Everything a caller needs to explain a refusal to the member.
	 *
	 * The old message was "Rate limit exceeded. Please try again later." -
	 * three separate controllers saying the same unactionable thing. It named
	 * no limit, so a member could not know they had one until they hit it, and
	 * "later" concealed a wait that is measured in hours. Every number needed
	 * to say something useful was already sitting in the transient; nothing
	 * read it.
	 *
	 * Note on the window: increment() rewrites the TTL on every action, so the
	 * 24 hours runs from the member's LAST action rather than their first.
	 * retry_after reports the real remaining time rather than the nominal day,
	 * because telling somebody "24 hours" when it is 3 is its own bug.
	 *
	 * @param int    $user_id     Member.
	 * @param string $action      create_posts | create_replies | vote.
	 * @param int    $trust_level Their trust level.
	 * @return array{limit:int,used:int,remaining:int,retry_after:int}
	 *         limit 0 means no limit applies to this member.
	 */
	public static function status( int $user_id, string $action, int $trust_level ): array {
		$none = array(
			'limit'       => 0,
			'used'        => 0,
			'remaining'   => 0,
			'retry_after' => 0,
		);

		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'jetonomy_moderate' ) ) {
			return $none;
		}

		$limits = self::get_limits( $trust_level );
		if ( ! isset( $limits[ $action ] ) ) {
			return $none;
		}

		$limit = (int) $limits[ $action ];
		$key   = "jetonomy_rate_{$user_id}_{$action}";
		$used  = (int) get_transient( $key );

		// The timeout row is the only place the remaining window is recorded.
		$timeout     = (int) get_option( '_transient_timeout_' . $key, 0 );
		$retry_after = $timeout > 0 ? max( 0, $timeout - time() ) : 0;

		return array(
			'limit'       => $limit,
			'used'        => $used,
			'remaining'   => max( 0, $limit - $used ),
			'retry_after' => $retry_after,
		);
	}

	/**
	 * The refusal, written for the member rather than the log.
	 *
	 * Shared by every controller that enforces a limit so the three cannot
	 * drift apart, which is how they came to say the same unhelpful sentence
	 * in the first place.
	 *
	 * @param int    $user_id     Member.
	 * @param string $action      create_posts | create_replies | vote.
	 * @param int    $trust_level Their trust level.
	 */
	public static function message( int $user_id, string $action, int $trust_level ): string {
		$status = self::status( $user_id, $action, $trust_level );

		if ( $status['limit'] <= 0 ) {
			// Should not be reachable - the caller only asks after a refusal -
			// but never leave a member with an empty string.
			return __( 'You have reached a posting limit. Please try again later.', 'jetonomy' );
		}

		/*
		 * Whole sentences per action, not a shared frame with a noun slotted
		 * in. A shared "can post %s" produced "can post 5 votes a day", and
		 * you do not post a vote - you cast one. Verbs are part of the string
		 * so translators get a real sentence rather than a fill-in-the-blank.
		 */
		$count     = number_format_i18n( $status['limit'] );
		$allowance = '';
		$again     = '';

		switch ( $action ) {
			case 'create_posts':
				/* translators: %s: number of topics allowed per day. */
				$allowance = sprintf( _n( 'New members can post %s topic a day.', 'New members can post %s topics a day.', $status['limit'], 'jetonomy' ), $count );
				/* translators: %s: human-readable wait, e.g. "17 hours". */
				$again = __( 'You can post again in about %s.', 'jetonomy' );
				break;
			case 'create_replies':
				/* translators: %s: number of replies allowed per day. */
				$allowance = sprintf( _n( 'New members can post %s reply a day.', 'New members can post %s replies a day.', $status['limit'], 'jetonomy' ), $count );
				/* translators: %s: human-readable wait, e.g. "45 minutes". */
				$again = __( 'You can reply again in about %s.', 'jetonomy' );
				break;
			case 'vote':
				/* translators: %s: number of votes allowed per day. */
				$allowance = sprintf( _n( 'New members can cast %s vote a day.', 'New members can cast %s votes a day.', $status['limit'], 'jetonomy' ), $count );
				/* translators: %s: human-readable wait, e.g. "2 minutes". */
				$again = __( 'You can vote again in about %s.', 'jetonomy' );
				break;
			default:
				/* translators: %s: number of actions allowed per day. */
				$allowance = sprintf( __( 'New members are limited to %s of these a day.', 'jetonomy' ), $count );
				/* translators: %s: human-readable wait. */
				$again = __( 'You can try again in about %s.', 'jetonomy' );
		}

		if ( $status['retry_after'] > 0 ) {
			return $allowance . ' ' . sprintf(
				$again,
				human_time_diff( time(), time() + $status['retry_after'] )
			);
		}

		return $allowance . ' ' . __( 'Please try again later.', 'jetonomy' );
	}

	/**
	 * Return the built-in default per-day rate limits for Level 0 users.
	 *
	 * Single source of truth consumed by the runtime reader, the admin
	 * sanitizer/view, and the activation seeder. Keys match the storage
	 * format in `jetonomy_settings[rate_limits]`.
	 *
	 * @return array<string,int>
	 */
	public static function defaults(): array {
		return [
			'posts'   => 3,
			'replies' => 10,
			'votes'   => 5,
		];
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
		$defaults = self::defaults();

		return [
			'create_posts'   => (int) ( $limits['posts'] ?? $defaults['posts'] ),
			'create_replies' => (int) ( $limits['replies'] ?? $defaults['replies'] ),
			'vote'           => (int) ( $limits['votes'] ?? $defaults['votes'] ),
		];
	}
}
