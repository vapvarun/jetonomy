<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Flag extends Model {

	protected static function table_name(): string {
		return 'flags';
	}

	/**
	 * Create a new flag report.
	 *
	 * Automatically sets status to 'pending' and created_at if absent.
	 *
	 * @param array $data Column data (object_type, object_id, flagged_by, reason, etc.).
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'status'     => 'pending',
				'created_at' => now(),
			],
			$data
		);

		return static::insert( $data );
	}

	/**
	 * List all flags with status 'pending', newest first.
	 *
	 * @return object[]
	 */
	public static function list_pending(): array {
		return static::db()->get_results(
			"SELECT * FROM " . static::table() . " WHERE status = 'pending' ORDER BY created_at DESC"
		) ?: [];
	}

	/**
	 * Resolve a flag (approve/dismiss) and record who resolved it.
	 *
	 * @param int    $id          Flag row ID.
	 * @param int    $resolved_by User ID of the moderator resolving the flag.
	 * @param string $status      New status value (e.g. 'approved', 'dismissed').
	 * @return bool True on success.
	 */
	public static function resolve( int $id, int $resolved_by, string $status ): bool {
		return static::update(
			$id,
			[
				'status'      => $status,
				'resolved_by' => $resolved_by,
				'resolved_at' => now(),
			]
		);
	}

	/**
	 * List flags filtered by status, newest first.
	 *
	 * @param string $status Row status value to filter by (e.g. 'pending', 'approved', 'dismissed').
	 * @param int    $limit  Maximum number of rows to return.
	 * @return object[]
	 */
	public static function list_by_status( string $status = 'pending', int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE status = %s ORDER BY created_at DESC LIMIT %d',
				$status,
				$limit
			)
		) ?: [];
	}

	/**
	 * Count how many flags exist for a given object (regardless of status).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return int
	 */
	public static function count_for_object( string $object_type, int $object_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				$object_id
			)
		);
	}
}
