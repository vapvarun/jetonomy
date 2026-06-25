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
	 * @param string $type   Object type (e.g. 'post', 'reply').
	 * @param int    $id     Object ID.
	 * @param int    $limit  Max rows to return. 0 = unbounded (default,
	 *                       preserves pre-1.4.3 behaviour for every caller
	 *                       that did not opt in to pagination).
	 * @param int    $offset Row offset. Ignored when $limit = 0.
	 * @return object[]
	 */
	public static function list_for_object( string $type, int $id, int $limit = 0, int $offset = 0 ): array {
		$base = 'SELECT * FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY created_at DESC';
		if ( $limit > 0 ) {
			return static::db()->get_results(
				static::db()->prepare( $base . ' LIMIT %d OFFSET %d', $type, $id, $limit, max( 0, $offset ) )
			) ?: [];
		}
		return static::db()->get_results(
			static::db()->prepare( $base, $type, $id )
		) ?: [];
	}

	/**
	 * Aggregate distinct (object_type, object_id) pairs that have ≥1
	 * revision, with revision count, last-edited timestamp, and the user
	 * id of whoever wrote the most recent snapshot. Powers the Revisions
	 * admin page list mode.
	 *
	 * Filters supported (all optional):
	 *   - object_type : 'post' or 'reply' to narrow the result
	 *   - date_from   : YYYY-MM-DD lower bound (last_edited >= ...)
	 *   - date_to     : YYYY-MM-DD upper bound (last_edited <= ...)
	 *
	 * The last_edited_by column is resolved with a correlated subquery
	 * against the same table so the answer always matches the row whose
	 * created_at == MAX(created_at) for that (type, id) pair — picking
	 * MAX(author_id) would lie when the latest edit isn't by the
	 * highest-numbered user.
	 *
	 * @param array{object_type?: string, date_from?: string, date_to?: string} $filters
	 * @param int                                                               $limit
	 * @param int                                                               $offset
	 * @return object[]
	 */
	public static function list_objects_with_revisions( array $filters = [], int $limit = 20, int $offset = 0 ): array {
		$wpdb       = static::db();
		$table      = static::table();
		$where_sql  = self::build_aggregate_where( $filters );
		$where      = $where_sql['sql'];
		$where_args = $where_sql['args'];

		// Correlated subquery picks the author of the row whose
		// created_at equals the per-group max — keeps the aggregate row
		// honest when edits arrive out of author order.
		$sql = "SELECT object_type, object_id,
		               COUNT(*) AS revision_count,
		               MAX(created_at) AS last_edited,
		               (
		                   SELECT author_id
		                   FROM {$table} r2
		                   WHERE r2.object_type = r1.object_type
		                     AND r2.object_id = r1.object_id
		                   ORDER BY r2.created_at DESC, r2.id DESC
		                   LIMIT 1
		               ) AS last_edited_by
		        FROM {$table} r1
		        WHERE {$where}
		        GROUP BY object_type, object_id
		        ORDER BY last_edited DESC
		        LIMIT %d OFFSET %d";

		$args = array_merge( $where_args, [ $limit, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Total number of distinct (object_type, object_id) pairs that match
	 * the active filters. Used to render the pager on the Revisions list
	 * mode without loading every aggregate row into memory.
	 *
	 * @param array{object_type?: string, date_from?: string, date_to?: string} $filters
	 */
	public static function count_objects_with_revisions( array $filters = [] ): int {
		$wpdb       = static::db();
		$table      = static::table();
		$where_sql  = self::build_aggregate_where( $filters );
		$where      = $where_sql['sql'];
		$where_args = $where_sql['args'];

		$sql = "SELECT COUNT(*) FROM (
		            SELECT 1
		            FROM {$table} r1
		            WHERE {$where}
		            GROUP BY object_type, object_id
		        ) AS sub";

		$prepared = $where_args ? $wpdb->prepare( $sql, ...$where_args ) : $sql;
		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * Shared WHERE builder for the aggregate query + its count partner.
	 * Returning a [sql, args] tuple keeps the two callers in lockstep so
	 * the page count never disagrees with the page rows.
	 *
	 * @param array{object_type?: string, date_from?: string, date_to?: string} $filters
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	private static function build_aggregate_where( array $filters ): array {
		$clauses = [ '1=1' ];
		$args    = [];

		$type = isset( $filters['object_type'] ) ? (string) $filters['object_type'] : '';
		if ( in_array( $type, [ 'post', 'reply' ], true ) ) {
			$clauses[] = 'object_type = %s';
			$args[]    = $type;
		}

		$from = isset( $filters['date_from'] ) ? (string) $filters['date_from'] : '';
		if ( '' !== $from ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = $from . ' 00:00:00';
		}

		$to = isset( $filters['date_to'] ) ? (string) $filters['date_to'] : '';
		if ( '' !== $to ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = $to . ' 23:59:59';
		}

		return [
			'sql'  => implode( ' AND ', $clauses ),
			'args' => $args,
		];
	}
}
