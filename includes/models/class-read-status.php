<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class ReadStatus extends Model {

	protected static function table_name(): string {
		return 'read_status';
	}

	/**
	 * Record (or update) the last-read reply for a user in a post thread.
	 *
	 * Uses REPLACE INTO because the table has a composite primary key
	 * (user_id + post_id) with no auto-increment ID.
	 *
	 * @param int $user_id
	 * @param int $post_id
	 * @param int $reply_id Last reply ID that the user has read.
	 */
	public static function mark_read( int $user_id, int $post_id, int $reply_id ): void {
		static::db()->query(
			static::db()->prepare(
				'REPLACE INTO ' . static::table() . ' (user_id, post_id, last_read_reply_id, updated_at) VALUES (%d, %d, %d, %s)',
				$user_id,
				$post_id,
				$reply_id,
				now()
			)
		);
	}

	/**
	 * Return the last reply ID a user has read in a post thread, or null if never read.
	 *
	 * @param int $user_id
	 * @param int $post_id
	 * @return int|null
	 */
	public static function get_last_read( int $user_id, int $post_id ): ?int {
		$value = static::db()->get_var(
			static::db()->prepare(
				'SELECT last_read_reply_id FROM ' . static::table() . ' WHERE user_id = %d AND post_id = %d',
				$user_id,
				$post_id
			)
		);

		return null !== $value ? (int) $value : null;
	}

	/**
	 * Check whether a user has unread replies in a post thread.
	 *
	 * Returns true if the user has never read the thread, or if their
	 * last-read reply ID is behind the latest reply in the thread.
	 *
	 * @param int $user_id
	 * @param int $post_id
	 * @param int $latest_reply_id The ID of the most recent reply in the thread.
	 * @return bool
	 */
	public static function has_unread( int $user_id, int $post_id, int $latest_reply_id ): bool {
		$last_read = static::get_last_read( $user_id, $post_id );

		if ( null === $last_read ) {
			return true;
		}

		return $last_read < $latest_reply_id;
	}
}
