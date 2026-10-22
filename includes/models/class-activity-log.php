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
	 * Net times $reason was applied to this object for $user_id: awards minus
	 * the matching "{$reason}_revoked" rows written by Reputation::revoke().
	 * The ledger lives in reputation_changed rows (Activity_Tracker::on_reputation).
	 * Rides the user_created index.
	 */
	public static function reputation_net( int $user_id, string $reason, string $object_type, int $object_id ): int {
		// shortcut: rows pruned by the activity-log retention window are forgotten. A
		// give-back after that window returns nothing (safe), but award_once() can then
		// award the same object again (e.g. spam -> 90+ days -> restore -> spam = a
		// second -20). Move the ledger to its own table if that ever matters to owners.
		$db = self::db();
		return (int) $db->get_var(
			$db->prepare(
				'SELECT SUM( CASE WHEN metadata LIKE %s THEN 1 WHEN metadata LIKE %s THEN -1 ELSE 0 END ) FROM ' . self::table() . " WHERE user_id = %d AND action = 'reputation_changed' AND object_type = %s AND object_id = %d",
				'%' . $db->esc_like( '"reason":"' . $reason . '"' ) . '%',
				'%' . $db->esc_like( '"reason":"' . $reason . '_revoked"' ) . '%',
				$user_id,
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
		[ $where, $args ] = self::filter_where( $filters );
		$sql              = 'SELECT id, user_id, action, object_type, object_id, metadata, created_at FROM ' . self::table() . ' WHERE ' . $where . ' ORDER BY created_at DESC LIMIT 50000';
		$rows             = $args ? self::db()->get_results( self::db()->prepare( $sql, ...$args ), ARRAY_A ) : self::db()->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only when $args.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One page of the wp-admin Activity Log, ordered by time.
	 *
	 * @param array{user_id:int,action:string,date_from:string,date_to:string} $filters Active filters.
	 * @param string                                                           $order   'ASC' or 'DESC'.
	 * @param int                                                              $limit   Page size.
	 * @param int                                                              $offset  Offset.
	 * @return object[]
	 */
	public static function list_filtered( array $filters, string $order, int $limit, int $offset ): array {
		[ $where, $args ] = self::filter_where( $filters );
		$order            = 'ASC' === $order ? 'ASC' : 'DESC';
		$sql              = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY created_at {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only the table name, placeholders and a whitelisted direction.
		return self::db()->get_results( self::db()->prepare( $sql, ...array_merge( $args, array( $limit, $offset ) ) ) ) ?: array();
	}

	/**
	 * Row count behind {@see self::list_filtered()}.
	 *
	 * @param array{user_id:int,action:string,date_from:string,date_to:string} $filters Active filters.
	 * @return int
	 */
	public static function count_filtered( array $filters ): int {
		[ $where, $args ] = self::filter_where( $filters );
		$sql              = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only when $args.
		return (int) ( $args ? self::db()->get_var( self::db()->prepare( $sql, ...$args ) ) : self::db()->get_var( $sql ) );
	}

	/**
	 * Every distinct non-empty action in the log, A to Z, for the type filter.
	 *
	 * @return string[]
	 */
	public static function list_actions(): array {
		return array_map( 'strval', self::db()->get_col( 'SELECT DISTINCT action FROM ' . self::table() . " WHERE action <> '' ORDER BY action ASC" ) );
	}


	/**
	 * Recent log rows since a moment about published topics and replies the
	 * viewer can read, newest first (GET /updates global scope). Other object
	 * types (joins, moderation) never appear, and private or hidden spaces
	 * count only for their members - Space::content_visibility_sql().
	 *
	 * @param string $since   UTC MySQL datetime (exclusive).
	 * @param int    $user_id Viewer.
	 * @return object[] Rows with action, object_type, object_id, created_at.
	 */
	public static function list_since_visible( string $since, int $user_id ): array {
		$posts_table              = table( 'posts' );
		$replies_table            = table( 'replies' );
		$spaces_table             = table( 'spaces' );
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( $user_id, 'vs' );
		// Correlated EXISTS, so the created_at bound picks the few new rows first
		// and each is checked by primary key, instead of scanning every post and
		// reply to build an id list (which grew with the forum, once per poll).
		$al = self::table();
		return self::db()->get_results(
			self::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names trusted; $vis_sql carries only %d placeholders bound below.
				"SELECT al.action, al.object_type, al.object_id, al.created_at
				 FROM {$al} al
				 WHERE al.created_at > %s
				   AND (
				     ( al.object_type = 'post' AND EXISTS ( SELECT 1 FROM {$posts_table} p INNER JOIN {$spaces_table} vs ON vs.id = p.space_id WHERE p.id = al.object_id AND p.status = 'publish' AND {$vis_sql} ) )
				     OR ( al.object_type = 'reply' AND EXISTS ( SELECT 1 FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id INNER JOIN {$spaces_table} vs ON vs.id = p.space_id WHERE r.id = al.object_id AND r.status = 'publish' AND p.status = 'publish' AND {$vis_sql} ) )
				   )
				 ORDER BY al.created_at DESC
				 LIMIT 50",
				...array_merge( array( $since ), $vis_params, $vis_params )
			)
		) ?: array();
	}

	/**
	 * Recent log rows since a moment about one space's topics and replies,
	 * newest first. The created_at filter runs first and the space match is an
	 * IN( subquery ), so a large space never becomes a literal id list. The
	 * caller checks the viewer may read the space.
	 *
	 * @param string $since    UTC MySQL datetime (exclusive).
	 * @param int    $space_id Space.
	 * @return object[] Rows with action, object_type, object_id, created_at.
	 */
	public static function list_since_in_space( string $since, int $space_id ): array {
		$posts_table   = table( 'posts' );
		$replies_table = table( 'replies' );
		return self::db()->get_results(
			self::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT action, object_type, object_id, created_at
				 FROM ' . self::table() . "
				 WHERE created_at > %s
				   AND (
				     ( object_type = 'post' AND object_id IN ( SELECT id FROM {$posts_table} WHERE space_id = %d ) )
				     OR ( object_type = 'reply' AND object_id IN ( SELECT r.id FROM {$replies_table} r INNER JOIN {$posts_table} p ON r.post_id = p.id WHERE p.space_id = %d ) )
				   )
				 ORDER BY created_at DESC
				 LIMIT 50",
				$since,
				$space_id,
				$space_id
			)
		) ?: array();
	}

	/**
	 * Delete up to $limit rows older than a moment (retention pruning).
	 *
	 * @param string $cutoff UTC MySQL datetime.
	 * @param int    $limit  Max rows per call.
	 * @return int Rows deleted.
	 */
	public static function delete_before( string $cutoff, int $limit ): int {
		return (int) self::db()->query(
			self::db()->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s LIMIT %d', $cutoff, $limit )
		);
	}

	/**
	 * WHERE clause and args for the Activity Log filters, shared by the list
	 * table and the CSV export so both show the same rows.
	 *
	 * @param array{user_id:int,action:string,date_from:string,date_to:string} $filters Filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function filter_where( array $filters ): array {
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
		return array( implode( ' AND ', $clauses ), $args );
	}
}
