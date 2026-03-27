<?php
/**
 * Revision model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Revision extends Model {

	protected static function table_name(): string {
		return 'revisions';
	}

	/**
	 * Store a new revision snapshot.
	 *
	 * Automatically sets created_at if absent.
	 *
	 * @param array $data Column data (object_type, object_id, edited_by, content_before, content_after, etc.).
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'created_at' => now(),
			],
			$data
		);

		return static::insert( $data );
	}

	/**
	 * List all revisions for a given object, newest first.
	 *
	 * @param string $type      Object type (e.g. 'post', 'reply').
	 * @param int    $id        Object ID.
	 * @return object[]
	 */
	public static function list_for_object( string $type, int $id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY created_at DESC',
				$type,
				$id
			)
		) ?: [];
	}
}
