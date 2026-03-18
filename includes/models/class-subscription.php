<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use function Jetonomy\table;

class Subscription extends Model {

	protected static function table_name(): string {
		return 'subscriptions';
	}

	/**
	 * Subscribe a user to an object. Silently ignores duplicate entries.
	 *
	 * @param int    $user_id
	 * @param string $object_type
	 * @param int    $object_id
	 * @param string $via         Notification channel: 'email', 'in_app', or 'both'.
	 * @return int Inserted row ID (0 if the row already existed due to INSERT IGNORE).
	 */
	public static function subscribe( int $user_id, string $object_type, int $object_id, string $via = 'both' ): int {
		$tbl = static::table();
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$tbl} (user_id, object_type, object_id, notify_via, created_at) VALUES (%d, %s, %d, %s, %s)",
				$user_id,
				$object_type,
				$object_id,
				$via,
				now()
			)
		);

		return (int) static::db()->insert_id;
	}

	/**
	 * Unsubscribe a user from an object.
	 *
	 * @param int    $user_id
	 * @param string $object_type
	 * @param int    $object_id
	 * @return bool True if a row was deleted.
	 */
	public static function unsubscribe( int $user_id, string $object_type, int $object_id ): bool {
		return false !== static::db()->delete(
			static::table(),
			[
				'user_id'     => $user_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
			]
		);
	}

	/**
	 * Check whether a user is subscribed to an object.
	 *
	 * @param int    $user_id
	 * @param string $object_type
	 * @param int    $object_id
	 * @return bool
	 */
	public static function is_subscribed( int $user_id, string $object_type, int $object_id ): bool {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE user_id = %d AND object_type = %s AND object_id = %d LIMIT 1',
				$user_id,
				$object_type,
				$object_id
			)
		);

		return null !== $row;
	}

	/**
	 * Return an array of user_ids subscribed to a given object.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return int[]
	 */
	public static function get_subscribers( string $object_type, int $object_id ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT user_id FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				$object_id
			)
		);

		if ( empty( $rows ) ) {
			return [];
		}

		return array_map( static fn( $row ) => (int) $row->user_id, $rows );
	}
}
