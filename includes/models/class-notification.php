<?php
/**
 * Notification model.
 *
 * @package Jetonomy
 */

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
		/**
		 * Filter whether ANY Jetonomy notification should be created at all.
		 *
		 * Global veto, not a per-type preference (preferences live in the
		 * notifier's own gates). Mirrors BuddyNext's
		 * buddynext_notification_should_send: buddynext-importer flips this
		 * to false for the duration of a migration run so imported forum
		 * content never fans out a notification per row. The same filter is
		 * honoured by Notifier::should_email(), so one veto silences rows
		 * and emails together.
		 *
		 * @since 1.8.1
		 *
		 * @param bool $should_send Whether to create the notification.
		 */
		if ( ! apply_filters( 'jetonomy_notification_should_send', true ) ) {
			return 0;
		}

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
		// Must match list_for_user_with_targets()'s block exclusion or the
		// header badge count disagrees with what the notifications list shows.
		[ $block_sql ] = BlockedUser::exclusion_sql( $user_id, '', 'actor_id' );
		$block_where   = '' !== $block_sql ? " AND {$block_sql}" : '';
		$table         = static::table();

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0{$block_where}",
				$user_id
			)
		);
	}

	/**
	 * Return the total notification count for a user (read + unread).
	 * Paired with list_for_user() for pagination totals.
	 *
	 * Accepts the same filter slugs as list_for_user_with_targets() so the
	 * paginator and the filter-tab badges agree on the visible row count.
	 *
	 * @param int    $user_id User whose notifications to count.
	 * @param string $filter  One of: all|unread|mentions|replies|votes|badges.
	 * @return int
	 */
	public static function count_for_user( int $user_id, string $filter = 'all' ): int {
		[ $where, $params ] = self::filter_where( $filter );

		// Must mirror list_for_user_with_targets()'s block exclusion exactly
		// or pagination totals disagree with the visible list.
		[ $block_sql ] = BlockedUser::exclusion_sql( $user_id, 'n', 'actor_id' );
		if ( '' !== $block_sql ) {
			$where .= ' AND ' . $block_sql;
		}

		$sql = 'SELECT COUNT(*) FROM ' . static::table() . ' n WHERE n.user_id = %d' . $where;

		return (int) static::db()->get_var(
			static::db()->prepare( $sql, array_merge( [ $user_id ], $params ) )
		);
	}

	/**
	 * Return a single page of notifications enriched with deep-link target
	 * data (post slug, space slug, parent reply id) so callers can build URLs
	 * without firing one query per row.
	 *
	 * The result includes every row's notification columns plus four extra
	 * fields: `post_slug`, `space_slug`, `reply_id`, and `reply_post_slug` —
	 * whichever apply for the row's object_type. Unmatched fields are null.
	 *
	 * Two LEFT JOIN chains run side-by-side: one for 'post' object_type, one
	 * for 'reply' object_type. The user_read_created index drives the WHERE
	 * + ORDER BY, and the joined tables are looked up by primary key.
	 *
	 * @param int    $user_id User whose notifications to return.
	 * @param int    $limit   Page size.
	 * @param int    $offset  Page offset.
	 * @param string $filter  One of: all|unread|mentions|replies|votes|badges.
	 * @return object[] Enriched notification rows, newest first.
	 */
	public static function list_for_user_with_targets( int $user_id, int $limit = 20, int $offset = 0, string $filter = 'all' ): array {
		global $wpdb;

		$notifs  = static::table();
		$posts   = \Jetonomy\table( 'posts' );
		$spaces  = \Jetonomy\table( 'spaces' );
		$replies = \Jetonomy\table( 'replies' );

		[ $where, $params ] = self::filter_where( $filter );

		// Hide notifications whose actor is a user the viewer has blocked.
		// no-op for guests/no-blocks. Must match count_for_user() exactly.
		[ $block_sql ] = BlockedUser::exclusion_sql( $user_id, 'n', 'actor_id' );
		if ( '' !== $block_sql ) {
			$where .= ' AND ' . $block_sql;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names interpolated; user data passed via $wpdb->prepare placeholders.
		$sql = "SELECT n.*,
				p.slug  AS post_slug,
				sp.slug AS space_slug,
				r.id    AS reply_id,
				rp.slug AS reply_post_slug,
				rsp.slug AS reply_space_slug
			FROM {$notifs} n
			LEFT JOIN {$posts}   p   ON ( n.object_type = 'post'  AND n.object_id = p.id )
			LEFT JOIN {$spaces}  sp  ON ( n.object_type = 'post'  AND sp.id = p.space_id )
			LEFT JOIN {$replies} r   ON ( n.object_type = 'reply' AND n.object_id = r.id )
			LEFT JOIN {$posts}   rp  ON ( n.object_type = 'reply' AND rp.id = r.post_id )
			LEFT JOIN {$spaces}  rsp ON ( n.object_type = 'reply' AND rsp.id = rp.space_id )
			WHERE n.user_id = %d{$where}
			ORDER BY n.created_at DESC
			LIMIT %d OFFSET %d";

		$prepared = $wpdb->prepare( $sql, array_merge( [ $user_id ], $params, [ $limit, $offset ] ) );
		$rows     = $wpdb->get_results( $prepared );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: [];
	}

	/**
	 * Delete a set of notifications belonging to a specific user.
	 *
	 * Ownership is enforced in the WHERE clause so even a forged ID list
	 * cannot remove another user's rows. Returns the number of rows actually
	 * deleted so the caller can report success vs. silent miss.
	 *
	 * @param int   $user_id User whose rows are being deleted.
	 * @param int[] $ids     Notification IDs to delete.
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_user( int $user_id, array $ids ): int {
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = 'DELETE FROM ' . static::table() . ' WHERE user_id = %d AND id IN (' . $placeholders . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated above; values passed via prepare.
		return (int) static::db()->query(
			static::db()->prepare( $sql, array_merge( [ $user_id ], $ids ) )
		);
	}

	/**
	 * Mark a set of notifications read for a specific user.
	 *
	 * Ownership enforced in the WHERE clause. Idempotent — already-read rows
	 * are no-ops.
	 *
	 * @param int   $user_id User whose rows to update.
	 * @param int[] $ids     Notification IDs to mark read.
	 * @return int Number of rows updated.
	 */
	public static function mark_read_for_user( int $user_id, array $ids ): int {
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = 'UPDATE ' . static::table() . ' SET is_read = 1 WHERE user_id = %d AND is_read = 0 AND id IN (' . $placeholders . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated above; values passed via prepare.
		return (int) static::db()->query(
			static::db()->prepare( $sql, array_merge( [ $user_id ], $ids ) )
		);
	}

	/**
	 * Return all filter-tab counts for a user in a single query.
	 *
	 * Used by the notifications page header to render badges next to each
	 * tab without firing one count query per filter. The SUM(CASE …) pattern
	 * lets the database walk the user's row set just once.
	 *
	 * Keys match the filter slugs accepted by list_for_user_with_targets() /
	 * count_for_user(): `all`, `unread`, `mentions`, `replies`, `votes`,
	 * `badges`.
	 *
	 * @param int $user_id User whose counts to fetch.
	 * @return array<string,int>
	 */
	public static function counts_by_filter( int $user_id ): array {
		// The filter-tab badges — same block exclusion as the list/unread
		// counts so a badge never advertises a blocked user's content.
		[ $block_sql ] = BlockedUser::exclusion_sql( $user_id, '', 'actor_id' );
		$block_where   = '' !== $block_sql ? " AND {$block_sql}" : '';
		$table         = static::table();

		$row = static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT
					COUNT(*)                                                              AS c_all,
					SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END)                          AS c_unread,
					SUM(CASE WHEN type = %s THEN 1 ELSE 0 END)                            AS c_mentions,
					SUM(CASE WHEN type IN (%s, %s) THEN 1 ELSE 0 END)                     AS c_replies,
					SUM(CASE WHEN type = %s THEN 1 ELSE 0 END)                            AS c_votes,
					SUM(CASE WHEN type = %s THEN 1 ELSE 0 END)                            AS c_badges
				FROM {$table}
				WHERE user_id = %d{$block_where}",
				'mention',
				'reply_to_post',
				'reply_to_reply',
				'vote_on_post',
				'badge_earned',
				$user_id
			)
		);

		if ( ! $row ) {
			return [
				'all'      => 0,
				'unread'   => 0,
				'mentions' => 0,
				'replies'  => 0,
				'votes'    => 0,
				'badges'   => 0,
			];
		}

		return [
			'all'      => (int) $row->c_all,
			'unread'   => (int) $row->c_unread,
			'mentions' => (int) $row->c_mentions,
			'replies'  => (int) $row->c_replies,
			'votes'    => (int) $row->c_votes,
			'badges'   => (int) $row->c_badges,
		];
	}

	/**
	 * Resolve a filter slug into a WHERE-fragment + placeholder values.
	 *
	 * Used by count_for_user() and list_for_user_with_targets() so the two
	 * stay in lockstep when new filter buckets are added.
	 *
	 * Filter slugs:
	 * - `all`      — no filter.
	 * - `unread`   — is_read = 0.
	 * - `mentions` — type = 'mention'.
	 * - `replies`  — type IN ('reply_to_post', 'reply_to_reply').
	 * - `votes`    — type = 'vote_on_post'.
	 * - `badges`   — type = 'badge_earned'.
	 *
	 * Unknown slugs collapse to `all` so a malformed query string never
	 * 500s the request — it just shows everything.
	 *
	 * @param string $filter Filter slug.
	 * @return array{0:string,1:array<int,mixed>} { WHERE fragment with leading space, placeholder values }
	 */
	private static function filter_where( string $filter ): array {
		switch ( $filter ) {
			case 'unread':
				return [ ' AND n.is_read = 0', [] ];

			case 'mentions':
				return [ ' AND n.type = %s', [ 'mention' ] ];

			case 'replies':
				return [ ' AND n.type IN (%s, %s)', [ 'reply_to_post', 'reply_to_reply' ] ];

			case 'votes':
				return [ ' AND n.type = %s', [ 'vote_on_post' ] ];

			case 'badges':
				return [ ' AND n.type = %s', [ 'badge_earned' ] ];

			default:
				return [ '', [] ];
		}
	}
}
