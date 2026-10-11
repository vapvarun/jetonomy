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
	public static function log( int $user_id, string $action, string $object_type, int $object_id, array $metadata = array() ): int {
		return self::insert(
			array(
				'user_id'     => $user_id,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'metadata'    => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
				'created_at'  => now(),
			)
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


	/**
	 * Distinct members with any logged activity since a moment.
	 *
	 * @param string $since UTC MySQL datetime.
	 * @return int
	 */
	public static function count_contributors_since( string $since ): int {
		return (int) self::db()->get_var(
			self::db()->prepare( 'SELECT COUNT(DISTINCT user_id) FROM ' . self::table() . ' WHERE created_at >= %s', $since )
		);
	}

	/**
	 * Rows for the Activity Log CSV export, newest first, capped at 50,000 so
	 * a loose filter cannot produce a huge download.
	 *
	 * @param array{user_id:int,action:string,date_from:string,date_to:string} $filters Same filters as the list table.
	 * @return array<int,array<string,mixed>>
	 */
	public static function export_rows( array $filters ): array {
		$clauses = array( '1=1' );
		$args    = array();
		if ( (int) $filters['user_id'] > 0 ) {
			$clauses[] = 'user_id = %d';
			$args[]    = (int) $filters['user_id'];
		}
		if ( '' !== (string) $filters['action'] ) {
			$clauses[] = 'action = %s';
			$args[]    = (string) $filters['action'];
		}
		if ( '' !== (string) $filters['date_from'] ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = $filters['date_from'] . ' 00:00:00';
		}
		if ( '' !== (string) $filters['date_to'] ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = $filters['date_to'] . ' 23:59:59';
		}
		$sql  = 'SELECT id, user_id, action, object_type, object_id, metadata, created_at FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $clauses ) . ' ORDER BY created_at DESC LIMIT 50000';
		$rows = $args ? self::db()->get_results( self::db()->prepare( $sql, ...$args ), ARRAY_A ) : self::db()->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only when $args.
		return is_array( $rows ) ? $rows : array();
	}
}
