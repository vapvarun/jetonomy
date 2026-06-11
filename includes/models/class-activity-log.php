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
	 * Get recent activity for a user.
	 */
	public static function list_for_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
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
				'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			)
		);
	}
}
