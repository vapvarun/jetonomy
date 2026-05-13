<?php
/**
 * User journey — create users, manage trust levels, profile, reputation.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\UserProfile;
use Jetonomy\Trust\Reputation;
use Jetonomy\Trust\Trust_Levels;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering every user-lifecycle action an operator can drive:
 * provision a WP user with an initial trust level, flip trust manually, patch
 * profile fields, nudge reputation, inspect the profile, list currently-online
 * users, and delegate bans to {@see Moderation_Journey} for cross-journey
 * composition.
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method takes plain
 * primitives or a payload array, delegates to the underlying model, and returns
 * a {@see Journey_Result}. Commands format the result for the terminal; PHPUnit
 * tests read the same fields and assert on them directly.
 *
 * Trust level storage: writes the `trust_level` column directly through
 * {@see UserProfile::update_profile()} — the model just forwards the key/value
 * map to `$wpdb->update()`, so any real column on `wp_jt_user_profiles` can be
 * flipped this way without bespoke setters.
 */
final class User_Journey {

	/**
	 * Columns on `wp_jt_user_profiles` that callers are allowed to patch via
	 * {@see update_profile()}. Kept narrow on purpose — the trust level and
	 * reputation have their own methods so a typo in a profile patch can't
	 * accidentally promote a user.
	 *
	 * @var array<int,string>
	 */
	private const PROFILE_WHITELIST = [
		'display_name',
		'bio',
		'avatar_url',
	];

	/**
	 * Create a WordPress user, ensure a Jetonomy profile row exists, and stamp
	 * an initial trust level on it.
	 *
	 * Required input keys: `login`, `email`.
	 * Optional: `password` (auto-generated if absent), `role` (default
	 * `subscriber`), `trust_level` (0–5, default 0), `display_name`.
	 *
	 * @param array<string,mixed> $input Create payload.
	 */
	public function create_with_trust_level( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'login', 'email' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$login = (string) $input['login'];
		$email = (string) $input['email'];
		$role  = (string) ( $input['role'] ?? 'subscriber' );
		$level = isset( $input['trust_level'] ) ? (int) $input['trust_level'] : 0;

		if ( $level < 0 || $level > 5 ) {
			return Journey_Result::fail( 'trust_level must be between 0 and 5.' );
		}

		$password = isset( $input['password'] ) && '' !== $input['password']
			? (string) $input['password']
			: wp_generate_password( 20, true, true );

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => isset( $input['display_name'] ) ? (string) $input['display_name'] : $login,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return Journey_Result::from_wp_error( $user_id );
		}

		UserProfile::find_or_create( (int) $user_id );

		$patch = [ 'trust_level' => $level ];
		if ( isset( $input['display_name'] ) && '' !== $input['display_name'] ) {
			$patch['display_name'] = (string) $input['display_name'];
		}

		$ok = UserProfile::update_profile( (int) $user_id, $patch );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'UserProfile::update_profile(%d) returned false.', (int) $user_id ) );
		}

		return Journey_Result::ok(
			[
				'user_id'     => (int) $user_id,
				'login'       => $login,
				'email'       => $email,
				'trust_level' => $level,
				'role'        => $role,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Manually set a user's trust level. Writes directly to the `trust_level`
	 * column via {@see UserProfile::update_profile()}.
	 *
	 * @param int $user_id Target user ID.
	 * @param int $level   Trust level (0–5).
	 */
	public function set_trust_level( int $user_id, int $level ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}
		if ( $level < 0 || $level > 5 ) {
			return Journey_Result::fail( 'trust_level must be between 0 and 5.' );
		}

		// Make sure a profile row exists — direct update silently no-ops on a missing row.
		UserProfile::find_or_create( $user_id );

		$ok = UserProfile::update_profile( $user_id, [ 'trust_level' => $level ] );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'UserProfile::update_profile(%d) returned false.', $user_id ) );
		}

		return Journey_Result::ok(
			[
				'user_id'     => $user_id,
				'trust_level' => $level,
				'level_name'  => Trust_Levels::name( $level ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Return the user's current trust level alongside the requirement block
	 * for the next level up, so operators can see what the user still needs.
	 *
	 * @param int $user_id Target user ID.
	 */
	public function get_trust_level( int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$profile = UserProfile::find_by_user( $user_id );
		if ( ! $profile ) {
			return Journey_Result::fail( sprintf( 'User profile %d not found.', $user_id ) );
		}

		$level = (int) ( $profile->trust_level ?? 0 );

		return Journey_Result::ok(
			[
				'user_id'      => $user_id,
				'trust_level'  => $level,
				'level_name'   => Trust_Levels::name( $level ),
				'requirements' => Trust_Levels::get_requirements( $level + 1 ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Patch one or more profile fields on an existing user. Only columns in
	 * {@see PROFILE_WHITELIST} are forwarded, so a stray key in the input array
	 * can't rewrite trust_level, reputation, or counters.
	 *
	 * @param int                 $user_id Target user ID.
	 * @param array<string,mixed> $changes Column → new value map.
	 */
	public function update_profile( int $user_id, array $changes ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$patch = array_intersect_key( $changes, array_flip( self::PROFILE_WHITELIST ) );
		if ( empty( $patch ) ) {
			return Journey_Result::fail(
				sprintf( 'No updatable fields provided. Allowed: %s', implode( ', ', self::PROFILE_WHITELIST ) )
			);
		}

		UserProfile::find_or_create( $user_id );

		$ok = UserProfile::update_profile( $user_id, $patch );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'UserProfile::update_profile(%d) returned false.', $user_id ) );
		}

		return Journey_Result::ok(
			[
				'user_id' => $user_id,
				'updated' => array_keys( $patch ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Nudge a user's reputation by the given delta (positive or negative) and
	 * return the new total so callers don't have to re-query.
	 *
	 * @param int $user_id Target user ID.
	 * @param int $delta   Amount to add; may be negative.
	 */
	public function adjust_reputation( int $user_id, int $delta ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		UserProfile::find_or_create( $user_id );
		Reputation::award_custom( $user_id, $delta, 'cli_manual_adjust' );

		$profile        = UserProfile::find_by_user( $user_id );
		$new_reputation = $profile ? (int) ( $profile->reputation ?? 0 ) : 0;

		return Journey_Result::ok(
			[
				'user_id'        => $user_id,
				'delta'          => $delta,
				'new_reputation' => $new_reputation,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Return the full profile row for a user, or fail if no profile exists.
	 *
	 * @param int $user_id Target user ID.
	 */
	public function get_profile( int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$profile = UserProfile::find_by_user( $user_id );
		if ( ! $profile ) {
			return Journey_Result::fail( 'User profile not found.' );
		}

		return Journey_Result::ok( (array) $profile, [], $this->duration_ms( $start ) );
	}

	/**
	 * List users seen within the last five minutes, shaped for render_list().
	 *
	 * Mirrors {@see UserProfile::is_online()} — "online" means `last_seen_at`
	 * within the last 300 seconds. Always paginated via $limit.
	 *
	 * @param int $limit Max rows to return (hard cap 200).
	 */
	public function list_online_users( int $limit = 20 ): Journey_Result {
		$start = microtime( true );

		if ( $limit <= 0 ) {
			$limit = 20;
		}
		if ( $limit > 200 ) {
			$limit = 200;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jt_user_profiles';

		$threshold = gmdate( 'Y-m-d H:i:s', time() - 300 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_id, display_name, trust_level, last_seen_at FROM {$table} WHERE last_seen_at IS NOT NULL AND last_seen_at > %s ORDER BY last_seen_at DESC LIMIT %d",
				$threshold,
				$limit
			)
		);

		$items = [];
		foreach ( (array) $rows as $row ) {
			$items[] = [
				'user_id'      => (int) $row->user_id,
				'display_name' => (string) ( $row->display_name ?? '' ),
				'trust_level'  => (int) ( $row->trust_level ?? 0 ),
				'last_seen_at' => (string) ( $row->last_seen_at ?? '' ),
			];
		}

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'user_id', 'display_name', 'trust_level', 'last_seen_at' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Ban a user. Delegates to {@see Moderation_Journey::ban_user()} so the
	 * User journey stays compositional rather than duplicating ban semantics,
	 * and operators get the same restriction row + rate limits regardless of
	 * which journey they dispatched from.
	 *
	 * @param int         $user_id   Target user ID.
	 * @param int         $issuer_id Issuing moderator/admin ID.
	 * @param string|null $reason    Optional human-readable reason.
	 */
	public function ban_user( int $user_id, int $issuer_id, ?string $reason = null ): Journey_Result {
		return ( new Moderation_Journey() )->ban_user( $user_id, $issuer_id, 'global_ban', null, $reason );
	}

	/**
	 * Return any required keys that are missing or empty in the input array.
	 *
	 * @param array<string,mixed> $input Input payload.
	 * @param array<int,string>   $keys  Required key names.
	 * @return array<int,string> Missing key names; empty if all present.
	 */
	private function require_keys( array $input, array $keys ): array {
		$missing = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
