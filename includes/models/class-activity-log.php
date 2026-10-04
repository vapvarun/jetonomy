<?php
/**
 * Activity log model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;
use function Jetonomy\now;

class ActivityLog extends Model {

	protected static function table_name(): string {
		return 'activity_log';
	}

	/**
	 * Log an activity event.
	 */
	public static function log( int $user_id, string $action, string $object_type, int $object_id, array $metadata = [] ): int {
		return self::insert(
			[
				'user_id'     => $user_id,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'metadata'    => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
				'created_at'  => now(),
			]
		);
	}

	/**
	 * Has $user_id logged $action on this object? Rides the user_created index.
	 */
	public static function exists_for( int $user_id, string $action, string $object_type, int $object_id ): bool {
		return (bool) self::db()->get_var(
			self::db()->prepare(
				'SELECT 1 FROM ' . self::table() . ' WHERE user_id = %d AND action = %s AND object_type = %s AND object_id = %d LIMIT 1',
				$user_id,
				$action,
				$object_type,
				$object_id
			)
		);
	}

	/**
	 * Get recent activity for a user.
	 */
	public static function list_for_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$user_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Get global activity feed.
	 */
	public static function list_recent( int $limit = 20, int $offset = 0 ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			)
		);
	}
}
