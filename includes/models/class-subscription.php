<?php
/**
 * Subscription model.
 *
 * @package Jetonomy
 */

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
	/**
	 * List a user's subscriptions, newest first, paginated.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Page size.
	 * @param int $offset  Offset.
	 * @return array<int,object>
	 */
	public static function list_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$user_id,
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Total subscriptions for a user (for pagination).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function count_for_user( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d',
				$user_id
			)
		);
	}

	/**
	 * Resolve target title/slug/exists onto subscription item arrays.
	 *
	 * Single source shared by the REST controller and the My Subscriptions
	 * template (moved here from the controller so the two surfaces can never
	 * drift). Two IN() batch queries - no per-row N+1.
	 *
	 * Post branch excludes the statuses that mean "gone". DELETE /posts/{id}
	 * is a SOFT delete (the REST controller sets status=trash; Post::delete()
	 * itself is a HARD delete), so `exists` alone only ever caught
	 * hard-deleted rows. Excluding rather than allow-listing `publish` on
	 * purpose: a pending/draft post is not gone - its author is subscribed to
	 * their own post while it waits on moderation.
	 *
	 * Space branch: the column is `title`, not `name` - jt_spaces has never
	 * had a `name` (the wrong column once made every space subscription
	 * render as "Space no longer available", Basecamp 10092766769).
	 *
	 * @param array<int,array<string,mixed>> $items Items with object_type + object_id.
	 * @return array<int,array<string,mixed>> Items with title, slug, exists added.
	 */
	public static function attach_targets( array $items ): array {
		$post_ids  = [];
		$space_ids = [];
		foreach ( $items as $item ) {
			if ( 'post' === ( $item['object_type'] ?? '' ) ) {
				$post_ids[] = (int) $item['object_id'];
			} elseif ( 'space' === ( $item['object_type'] ?? '' ) ) {
				$space_ids[] = (int) $item['object_id'];
			}
		}

		global $wpdb;
		$titles = [];

		if ( $post_ids ) {
			$posts_tbl = table( 'posts' );
			$ph        = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$spaces_tbl_j = table( 'spaces' );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.title, p.slug, s.slug AS space_slug FROM {$posts_tbl} p LEFT JOIN {$spaces_tbl_j} s ON s.id = p.space_id WHERE p.id IN ({$ph}) AND p.status NOT IN ('trash','spam')", ...$post_ids ) ) ?: [];
			foreach ( $rows as $row ) {
				$titles[ 'post:' . (int) $row->id ] = [
					'title'      => $row->title ?? '',
					'slug'       => $row->slug ?? '',
					'space_slug' => $row->space_slug ?? '',
				];
			}
		}

		if ( $space_ids ) {
			$spaces_tbl = table( 'spaces' );
			$ph         = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, slug FROM {$spaces_tbl} WHERE id IN ({$ph})", ...$space_ids ) ) ?: [];
			foreach ( $rows as $row ) {
				$titles[ 'space:' . (int) $row->id ] = [
					'title' => $row->title ?? '',
					'slug'  => $row->slug ?? '',
				];
			}
		}

		foreach ( $items as &$item ) {
			$key            = ( $item['object_type'] ?? '' ) . ':' . (int) ( $item['object_id'] ?? 0 );
			$target         = $titles[ $key ] ?? null;
			$item['title']  = $target['title'] ?? '';
			$item['slug']   = $target['slug'] ?? '';
			$item['exists'] = null !== $target;
			if ( isset( $target['space_slug'] ) ) {
				$item['space_slug'] = $target['space_slug'];
			}
		}
		unset( $item );

		return $items;
	}

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
	 * Count subscribers of an object without materialising the list.
	 *
	 * Lets the notifier decide whether to fan out inline (small sets) or defer
	 * to the background queue (large sets). Uses the object_lookup index.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return int
	 */
	public static function count_subscribers( string $object_type, int $object_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				$object_id
			)
		);
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
