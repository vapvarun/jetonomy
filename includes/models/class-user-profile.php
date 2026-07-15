<?php
/**
 * User profile model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use Jetonomy\Cache;

class UserProfile extends Model {

	/**
	 * Seconds is_online() caches its verdict. Presence tolerates this staleness
	 * by design, so the key is never busted — it ages out (Caching Standard §4b).
	 */
	private const ONLINE_TTL = 60;

	protected static function table_name(): string {
		return 'user_profiles';
	}

	/**
	 * Find an existing profile or create one with defaults.
	 *
	 * @param int $user_id
	 * @return object
	 */
	public static function find_or_create( int $user_id ): object {
		$existing = static::find_by_user( $user_id );

		if ( $existing ) {
			return $existing;
		}

		$now = now();
		static::db()->insert(
			static::table(),
			[
				'user_id'    => $user_id,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		// Bust the cache so find_by_user() hits the DB and returns the newly-inserted row.
		Cache::delete( "profile:{$user_id}" );

		return static::find_by_user( $user_id );
	}

	/**
	 * Find a profile by user ID, with 2-minute object-cache.
	 *
	 * @param int $user_id
	 * @return object|null
	 */
	public static function find_by_user( int $user_id ): ?object {
		return Cache::remember_object(
			"profile:{$user_id}",
			function () use ( $user_id ) {
				$row = static::db()->get_row(
					static::db()->prepare(
						'SELECT * FROM ' . static::table() . ' WHERE user_id = %d',
						$user_id
					)
				);
				return $row ?: null;
			},
			120
		);
	}

	/**
	 * Update profile fields for a user and invalidate the cache.
	 *
	 * @param int   $user_id
	 * @param array $data Column data to update.
	 * @return bool
	 */
	public static function update_profile( int $user_id, array $data ): bool {
		// Bust AFTER the write. Busting first is a re-prime race: a concurrent
		// find_by_user() between the delete and the update re-caches the old row.
		$result = false !== static::db()->update(
			static::table(),
			$data,
			[ 'user_id' => $user_id ]
		);
		Cache::delete( "profile:{$user_id}" );
		return $result;
	}

	/**
	 * Apply a raw reputation delta to a user profile and invalidate the cache.
	 *
	 * @internal
	 *
	 * This is the low-level persistence primitive. It does NOT fire the
	 * `jetonomy_reputation_changed` action and does NOT consult POINTS_MAP.
	 *
	 * Public callers MUST NOT invoke this directly. Use one of:
	 *   - {@see \Jetonomy\Trust\Reputation::award()}        for known POINTS_MAP actions
	 *   - {@see \Jetonomy\Trust\Reputation::revoke()}       to reverse a previous award
	 *   - {@see \Jetonomy\Trust\Reputation::award_custom()} for dynamic deltas
	 *
	 * Remains `public static` only so the `Reputation` facade (different namespace)
	 * can call it; treat as package-private.
	 *
	 * @param int $user_id WP user ID.
	 * @param int $delta   Amount to add (use negative value to subtract).
	 */
	public static function _apply_reputation_delta( int $user_id, int $delta ): void { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Underscore marks this as package-private; renaming would break the Reputation facade.
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET reputation = reputation + %d WHERE user_id = %d',
				$delta,
				$user_id
			)
		);
		// Bust AFTER the write (re-prime race — see update_profile()).
		Cache::delete( "profile:{$user_id}" );
	}

	/**
	 * Adjust the post_count for a user profile.
	 *
	 * Pass a negative value to decrement. Uses GREATEST() to prevent
	 * the counter from going below zero.
	 *
	 * @param int $user_id User ID.
	 * @param int $by      Amount to adjust (default +1).
	 */
	public static function increment_post_count( int $user_id, int $by = 1 ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET post_count = GREATEST(post_count + %d, 0) WHERE user_id = %d',
				$by,
				$user_id
			)
		);
		Cache::delete( "profile:{$user_id}" );
	}

	/**
	 * Adjust the reply_count for a user profile.
	 *
	 * Pass a negative value to decrement. Uses GREATEST() to prevent
	 * the counter from going below zero.
	 *
	 * @param int $user_id User ID.
	 * @param int $by      Amount to adjust (default +1).
	 */
	public static function increment_reply_count( int $user_id, int $by = 1 ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET reply_count = GREATEST(reply_count + %d, 0) WHERE user_id = %d',
				$by,
				$user_id
			)
		);
		Cache::delete( "profile:{$user_id}" );
	}

	/**
	 * Update the last_seen_at timestamp for a user.
	 *
	 * Uses a transient to rate-limit updates to once per minute,
	 * avoiding excessive DB writes on every page load.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function update_last_seen( int $user_id ): void {
		$key = 'jetonomy_seen_' . $user_id;
		if ( get_transient( $key ) ) {
			return; // Already updated within last minute.
		}

		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET last_seen_at = %s WHERE user_id = %d',
				now(),
				$user_id
			)
		);

		// Bust the profile cache so is_online() reads a fresh last_seen_at.
		Cache::delete( "profile:{$user_id}" );

		set_transient( $key, 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Check if a user is currently online (active within last 5 minutes).
	 *
	 * Result is cached in the object cache for 60 seconds to avoid
	 * N+1 queries when rendering reply lists.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function is_online( int $user_id ): bool {
		$key    = 'online_' . $user_id;
		$cached = Cache::get( $key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// Presence tolerates up to ONLINE_TTL of staleness by design (Caching
		// Standard §4b) — update_last_seen() does not bust this key; it ages out.
		$profile = static::find_by_user( $user_id );
		if ( ! $profile || empty( $profile->last_seen_at ) ) {
			Cache::set( $key, 0, self::ONLINE_TTL );
			return false;
		}

		$online = ( strtotime( $profile->last_seen_at ) > ( time() - 300 ) );
		Cache::set( $key, (int) $online, self::ONLINE_TTL );

		return $online;
	}

	/**
	 * Return the decoded settings array for a user profile.
	 *
	 * @param int $user_id
	 * @return array Settings key/value pairs, or empty array if none.
	 */
	public static function get_settings( int $user_id ): array {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT settings FROM ' . static::table() . ' WHERE user_id = %d',
				$user_id
			)
		);

		if ( ! $row || empty( $row->settings ) ) {
			return [];
		}

		$decoded = json_decode( $row->settings, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Build the recent-activity WHERE clause for leaderboard queries.
	 *
	 * Centralises the period window shared by the server-rendered leaderboard
	 * view and the REST leaderboards controller so both surfaces filter on an
	 * identical population. Returns an empty string for the all-time board.
	 *
	 * @param string $period One of 'week', 'month', or 'all' (default).
	 * @return string SQL fragment beginning with ' WHERE ', or '' for all-time.
	 */
	protected static function leaderboard_period_where( string $period ): string {
		if ( 'week' === $period ) {
			return ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
		}
		if ( 'month' === $period ) {
			return ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 30 DAY)';
		}
		return '';
	}

	/**
	 * Count profiles eligible for the leaderboard in a given period.
	 *
	 * Single source of truth for the leaderboard total, used to compute
	 * accurate pagination on both the server-rendered view and the REST
	 * response. Replaces the per-surface `count( $rows ) >= $per_page`
	 * heuristic that rendered a phantom "Load More" when the total was an
	 * exact multiple of the page size.
	 *
	 * @param string $period One of 'week', 'month', or 'all'.
	 * @return int Total profile count for the period.
	 */
	public static function count_for_leaderboard( string $period = 'all' ): int {
		$where = static::leaderboard_period_where( $period );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) static::db()->get_var( 'SELECT COUNT(*) FROM ' . static::table() . $where );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fetch one ranked page of leaderboard profiles.
	 *
	 * Shared by the server-rendered leaderboard view and the REST controller
	 * so both render an identical, identically-ordered page.
	 *
	 * @param string $period   One of 'week', 'month', or 'all'.
	 * @param int    $limit    Page size.
	 * @param int    $offset   Row offset.
	 * @param string $order_by ORDER BY clause. Trusted: callers pass a fixed
	 *                         literal or a value already vetted via the
	 *                         `jetonomy_users_query_args` filter. Defaults to
	 *                         'reputation DESC'.
	 * @return object[] Profile rows for the page (empty array when none).
	 */
	public static function list_for_leaderboard( string $period = 'all', int $limit = 20, int $offset = 0, string $order_by = 'reputation DESC' ): array {
		// Deliberately NOT block-filtered — a ranking, not a content feed.
		// Per-viewer filtering would re-rank the board and leak "you blocked
		// someone" via rank gaps.
		$where = static::leaderboard_period_where( $period );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . $where . ' ORDER BY ' . $order_by . ' LIMIT %d OFFSET %d',
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $rows ? $rows : [];
	}

	/**
	 * Competition rank of one user on the leaderboard for a period.
	 *
	 * Two scalar queries (the user's reputation, then a COUNT of profiles ahead
	 * of them) using the same period filter as the board — O(1), no full scan.
	 * Ties share a rank (standard "1224" competition ranking). Returns 0 when
	 * the user has no profile or isn't active in the period (i.e. unranked).
	 *
	 * @param int    $user_id User to rank.
	 * @param string $period  One of 'week', 'month', or 'all'.
	 * @return int Rank (1-based), or 0 if the user is not on this board.
	 */
	public static function rank_for_user( int $user_id, string $period = 'all' ): int {
		if ( $user_id < 1 ) {
			return 0;
		}
		$where = static::leaderboard_period_where( $period );
		$glue  = '' === $where ? ' WHERE' : ' AND';
		$table = static::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rep = static::db()->get_var(
			static::db()->prepare(
				'SELECT reputation FROM ' . $table . $where . $glue . ' user_id = %d',
				$user_id
			)
		);
		if ( null === $rep ) {
			return 0;
		}
		$ahead = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . $table . $where . $glue . ' reputation > %d',
				(int) $rep
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $ahead + 1;
	}
}
