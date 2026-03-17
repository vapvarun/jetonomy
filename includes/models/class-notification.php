<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Notification extends Model {

	protected static function table_name(): string {
		return 'notifications';
	}

	/**
	 * Create a new notification.
	 *
	 * Automatically sets is_read to 0 and created_at if absent.
	 *
	 * @param array $data Column data (user_id, type, object_type, object_id, actor_id, etc.).
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'is_read'    => 0,
				'created_at' => now(),
			],
			$data
		);

		return static::insert( $data );
	}

	/**
	 * List notifications for a user, newest first.
	 *
	 * @param int $user_id
	 * @param int $limit
	 * @param int $offset
	 * @return object[]
	 */
	public static function list_for_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$user_id,
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Mark a single notification as read.
	 *
	 * @param int $id Notification row ID.
	 * @return bool True on success.
	 */
	public static function mark_read( int $id ): bool {
		return static::update( $id, [ 'is_read' => 1 ] );
	}

	/**
	 * Mark all unread notifications for a user as read.
	 *
	 * @param int $user_id
	 */
	public static function mark_all_read( int $user_id ): void {
		static::db()->update(
			static::table(),
			[ 'is_read' => 1 ],
			[
				'user_id' => $user_id,
				'is_read' => 0,
			]
		);
	}

	/**
	 * Return the count of unread notifications for a user.
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function unread_count( int $user_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE user_id = %d AND is_read = 0',
				$user_id
			)
		);
	}
}
