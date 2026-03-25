<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use Jetonomy\Cache;

class UserProfile extends Model {

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
		return Cache::remember(
			"profile:{$user_id}",
			function() use ( $user_id ) {
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
		Cache::delete( "profile:{$user_id}" );
		return false !== static::db()->update(
			static::table(),
			$data,
			[ 'user_id' => $user_id ]
		);
	}

	/**
	 * Add or subtract from a user's reputation score, then invalidate the cache.
	 *
	 * @param int $user_id
	 * @param int $delta Amount to add (use negative value to subtract).
	 */
	public static function adjust_reputation( int $user_id, int $delta ): void {
		Cache::delete( "profile:{$user_id}" );
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET reputation = reputation + %d WHERE user_id = %d',
				$delta,
				$user_id
			)
		);
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
	}

	/**
	 * Update the last_seen_at timestamp for a user.
	 *
	 * @param int $user_id
	 */
	public static function update_last_seen( int $user_id ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET last_seen_at = %s WHERE user_id = %d',
				now(),
				$user_id
			)
		);
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
}
