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
		 * @since 1.9.0
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

		$id = static::insert( $data );

		if ( $id > 0 && ! empty( $data['user_id'] ) ) {
			self::bust_user_cache( (int) $data['user_id'] );
		}

		return $id;
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
		[ $visible_sql, $visible_params ] = self::visibility_sql( $user_id );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + fixed fragment from visibility_sql(); values bound below.
				'SELECT n.* FROM ' . static::table() . ' n WHERE n.user_id = %d' . $visible_sql . ' ORDER BY n.created_at DESC, n.id DESC LIMIT %d OFFSET %d',
				array_merge( [ $user_id ], $visible_params, [ $limit, $offset ] )
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
		// Load first: the row id alone can't name whose counters to bust.
		$row    = static::find( $id );
		$result = static::update( $id, [ 'is_read' => 1 ] );
		if ( $row && ! empty( $row->user_id ) ) {
			self::bust_user_cache( (int) $row->user_id );
		}
		return $result;
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
		self::bust_user_cache( $user_id );
	}

	/**
	 * Return the count of unread notifications for a user.
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function unread_count( int $user_id ): int {
		// Cached 60s (plan WP4.7): the highest-QPS query in the plugin —
		// the header bell reads it on EVERY page view for every logged-in
		// user. Busted by every named write path via bust_user_cache();
		// the cron bulk mark-read/prune, space purge and privacy erase have
		// no per-user loop and are TTL-BOUNDED (≤60s stale badge) — do not
		// claim they bust.
		$cached = \Jetonomy\Cache::get( "notif:unread:{$user_id}" );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Same visibility rules as every list/count path (visibility_sql()) or
		// the header badge disagrees with what the notifications list shows.
		[ $visible_sql, $visible_params ] = self::visibility_sql( $user_id );
		$table                            = static::table();

		$count = (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} n WHERE n.user_id = %d AND n.is_read = 0{$visible_sql}",
				array_merge( [ $user_id ], $visible_params )
			)
		);

		\Jetonomy\Cache::set( "notif:unread:{$user_id}", $count, 60 );
		return $count;
	}

	/**
	 * Bust the cached notification counters for one user (plan WP4.7).
	 *
	 * The single bust point for notif:unread:{id} + notif:counts:{id} —
	 * called from create(), mark_read(), mark_all_read(), delete_for_user(),
	 * mark_read_for_user(), and BlockedUser::bust_cache() (the counts apply
	 * the block exclusion, so blocking changes them).
	 *
	 * @param int $user_id Recipient whose counters changed.
	 */
	public static function bust_user_cache( int $user_id ): void {
		\Jetonomy\Cache::delete_many(
			array(
				"notif:unread:{$user_id}",
				"notif:counts:{$user_id}",
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

		// Must mirror list_for_user_with_targets() exactly or pagination
		// totals disagree with the visible list.
		[ $visible_sql, $visible_params ] = self::visibility_sql( $user_id );
		$where                           .= $visible_sql;
		$params                           = array_merge( $params, $visible_params );

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

		// Blocked / banned actors and dead targets. Must match count_for_user().
		[ $visible_sql, $visible_params ] = self::visibility_sql( $user_id );
		$where                           .= $visible_sql;
		$params                           = array_merge( $params, $visible_params );

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
			ORDER BY n.created_at DESC, n.id DESC
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
		$deleted = (int) static::db()->query(
			static::db()->prepare( $sql, array_merge( [ $user_id ], $ids ) )
		);
		self::bust_user_cache( $user_id );
		return $deleted;
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
		$updated = (int) static::db()->query(
			static::db()->prepare( $sql, array_merge( [ $user_id ], $ids ) )
		);
		self::bust_user_cache( $user_id );
		return $updated;
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
		// Cached 60s alongside notif:unread (plan WP4.7) — this single-pass
		// SUM(CASE) walks the user's ENTIRE notification history per render.
		// Same bust point (bust_user_cache), same TTL-bounded exceptions.
		$cached = \Jetonomy\Cache::get( "notif:counts:{$user_id}" );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// The filter-tab badges — same visibility rules as the list/unread
		// counts so a badge never advertises a row the list will not show.
		[ $visible_sql, $visible_params ] = self::visibility_sql( $user_id );
		$table                            = static::table();

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
				FROM {$table} n
				WHERE n.user_id = %d{$visible_sql}",
				array_merge(
					[ 'mention', 'reply_to_post', 'reply_to_reply', 'vote_on_post', 'badge_earned', $user_id ],
					$visible_params
				)
			)
		);

		$counts = $row ? [
			'all'      => (int) $row->c_all,
			'unread'   => (int) $row->c_unread,
			'mentions' => (int) $row->c_mentions,
			'replies'  => (int) $row->c_replies,
			'votes'    => (int) $row->c_votes,
			'badges'   => (int) $row->c_badges,
		] : [
			'all'      => 0,
			'unread'   => 0,
			'mentions' => 0,
			'replies'  => 0,
			'votes'    => 0,
			'badges'   => 0,
		];

		\Jetonomy\Cache::set( "notif:counts:{$user_id}", $counts, 60 );
		return $counts;
	}

	/**
	 * Notification types that stay visible even when their target is no
	 * longer published. Their whole message IS the status change ("your post
	 * was removed by a moderator", "your report was reviewed", "new flag to
	 * review"), so hiding them once the target left `publish` would swallow
	 * the only word the recipient gets.
	 */
	private const TARGET_STATUS_EXEMPT_TYPES = [ 'moderation', 'flag_resolved' ];

	/**
	 * The one WHERE fragment every notification read path applies, so the
	 * bell, the filter-tab badges, the paginated list, and the app/REST/CLI
	 * surfaces can never disagree on what a recipient is shown.
	 *
	 * A row is hidden when:
	 * - its actor is someone the viewer blocked (BlockedUser::exclusion_sql);
	 * - its actor is under an active site-wide ban (global_ban, unexpired) -
	 *   served by jt_restrictions.user_type_space (user_id, type, ...);
	 * - it points at a post or reply that is no longer publish (trash, spam,
	 *   pending, draft), or a reply whose parent post is not - so a tap never
	 *   lands on a 403 dead end. Primary-key lookups only. Written as "no
	 *   non-publish target" rather than "a publish target exists" on purpose:
	 *   hard deletes already remove their notifications (Post/Reply::delete(),
	 *   space purge), and a row whose target id resolves to nothing is left
	 *   to the renderer rather than silently dropped.
	 *
	 * Rows are not deleted: unbanning the actor or restoring the content brings
	 * them back. The cached unread/tab counters are TTL-bounded (60s) against
	 * a ban or a trash, the same as the other no-per-user-loop writes noted in
	 * unread_count().
	 *
	 * Requires the notifications table to be aliased `n`. targets_visible() is
	 * its PHP twin for a host that keeps its own copy of the rows - change both
	 * together (NotificationVisibilityParityTest enforces it).
	 *
	 * @param int $user_id Recipient (viewer).
	 * @return array{0:string,1:array<int,mixed>} { fragment with leading " AND", placeholder values }
	 */
	private static function visibility_sql( int $user_id ): array {
		$restrictions = \Jetonomy\table( 'restrictions' );
		$posts        = \Jetonomy\table( 'posts' );
		$replies      = \Jetonomy\table( 'replies' );
		$exempt       = "'" . implode( "','", self::TARGET_STATUS_EXEMPT_TYPES ) . "'";

		$sql = " AND NOT EXISTS ( SELECT 1 FROM {$restrictions} nvr WHERE nvr.user_id = n.actor_id AND nvr.type = 'global_ban' AND ( nvr.expires_at IS NULL OR nvr.expires_at > %s ) )"
			. " AND ( n.type IN ({$exempt})"
			. " OR ( n.object_type = 'post' AND NOT EXISTS ( SELECT 1 FROM {$posts} nvp WHERE nvp.id = n.object_id AND nvp.status <> 'publish' ) )"
			. " OR ( n.object_type = 'reply' AND NOT EXISTS ( SELECT 1 FROM {$replies} nvy INNER JOIN {$posts} nvyp ON nvyp.id = nvy.post_id WHERE nvy.id = n.object_id AND ( nvy.status <> 'publish' OR nvyp.status <> 'publish' ) ) )"
			. " OR n.object_type NOT IN ('post','reply') )";

		$params = [ now() ];

		// Carry the block fragment's own params: past INLINE_CAP it switches to
		// a `blocker_id = %d` subquery, which the old per-method copies dropped.
		[ $block_sql, $block_params ] = BlockedUser::exclusion_sql( $user_id, 'n', 'actor_id' );
		if ( '' !== $block_sql ) {
			$sql   .= ' AND ' . $block_sql;
			$params = array_merge( $params, $block_params );
		}

		return [ $sql, $params ];
	}

	/**
	 * The PHP twin of visibility_sql(): which of these notification targets a
	 * viewer may see, by the same rule, for a host plugin that holds its own
	 * copy of the rows (the BuddyNext bell). SQL cannot be called from PHP, so
	 * the rule is written twice; NotificationVisibilityParityTest runs both over
	 * one fixture set and fails if they ever disagree.
	 *
	 * @param int                                                                                    $viewer_id Recipient viewing their list.
	 * @param array<int|string,array{type?:string,object_type?:string,object_id?:int,actor_id?:int}> $targets   Rows on this page, any keys.
	 * @return array<int|string,bool> Same keys, true = visible.
	 */
	public static function targets_visible( int $viewer_id, array $targets ): array {
		$visible = array_fill_keys( array_keys( $targets ), true );
		if ( empty( $targets ) ) {
			return $visible;
		}

		global $wpdb;

		// Actors under an active site-wide ban: one query for the page.
		$actor_ids = array_values( array_unique( array_filter( array_map( static fn( $t ) => (int) ( $t['actor_id'] ?? 0 ), $targets ) ) ) );
		$banned    = array();
		if ( ! empty( $actor_ids ) ) {
			$in = implode( ',', array_fill( 0, count( $actor_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted prefixed table, %d placeholders.
			$banned = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM ' . \Jetonomy\table( 'restrictions' ) . " WHERE type = 'global_ban' AND ( expires_at IS NULL OR expires_at > %s ) AND user_id IN ({$in})", array_merge( array( now() ), $actor_ids ) ) ) );
		}
		$blocked = BlockedUser::blocked_ids( $viewer_id );

		$post_keys  = array();
		$reply_keys = array();
		foreach ( $targets as $key => $t ) {
			$actor = (int) ( $t['actor_id'] ?? 0 );
			if ( $actor > 0 && ( in_array( $actor, $banned, true ) || in_array( $actor, $blocked, true ) ) ) {
				$visible[ $key ] = false;
				continue;
			}
			$id = (int) ( $t['object_id'] ?? 0 );
			if ( $id <= 0 || in_array( (string) ( $t['type'] ?? '' ), self::TARGET_STATUS_EXEMPT_TYPES, true ) ) {
				continue;
			}
			switch ( (string) ( $t['object_type'] ?? '' ) ) {
				case 'post':
					$post_keys[ $key ] = $id;
					break;
				case 'reply':
					$reply_keys[ $key ] = $id;
					break;
			}
		}

		// Dead targets: one query per table for the page. A target that resolves
		// to nothing stays visible, exactly as visibility_sql() leaves it.
		$dead_posts = array();
		if ( ! empty( $post_keys ) ) {
			$ids = array_values( array_unique( $post_keys ) );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted prefixed table, %d placeholders.
			$dead_posts = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . \Jetonomy\table( 'posts' ) . " WHERE status <> 'publish' AND id IN ({$in})", $ids ) ) );
		}
		$dead_replies = array();
		if ( ! empty( $reply_keys ) ) {
			$ids = array_values( array_unique( $reply_keys ) );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted prefixed table, %d placeholders.
			$dead_replies = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT r.id FROM ' . \Jetonomy\table( 'replies' ) . ' r INNER JOIN ' . \Jetonomy\table( 'posts' ) . " p ON p.id = r.post_id WHERE ( r.status <> 'publish' OR p.status <> 'publish' ) AND r.id IN ({$in})", $ids ) ) );
		}

		foreach ( $post_keys as $key => $id ) {
			if ( in_array( $id, $dead_posts, true ) ) {
				$visible[ $key ] = false;
			}
		}
		foreach ( $reply_keys as $key => $id ) {
			if ( in_array( $id, $dead_replies, true ) ) {
				$visible[ $key ] = false;
			}
		}

		return $visible;
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
