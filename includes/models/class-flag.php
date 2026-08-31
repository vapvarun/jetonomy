<?php
/**
 * Flag model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Flag extends Model {

	protected static function table_name(): string {
		return 'flags';
	}

	/**
	 * Per-request memo for has_reported(), filled per-row on miss and in bulk
	 * (negatives included) by reporter_flag_map(). Mirrors the Vote memo: list
	 * paths warm the whole page in one query, and every per-row read after that
	 * is free. Cleared by create() and via Cache::flush().
	 *
	 * @var array<string, bool>
	 */
	private static array $memo = array();

	/**
	 * Whether the memo reset is registered with Cache::flush().
	 *
	 * @var bool
	 */
	private static bool $memo_registered = false;

	/**
	 * Empty the flag memo. Called from create().
	 */
	public static function reset_memo(): void {
		self::$memo = array();
	}

	/**
	 * Register the memo reset with the shared registry once.
	 */
	private static function register_memo_reset(): void {
		if ( ! self::$memo_registered ) {
			self::$memo_registered = true;
			\Jetonomy\Cache::register_memo_reset(
				static function (): void {
					self::$memo = array();
				}
			);
		}
	}

	/**
	 * Batch reporter lookup — which of these objects has this user reported?
	 *
	 * One query for a whole page so list enrichment never runs a per-row
	 * lookup. Uses the `reporter` index; misses are memoized as false too, so
	 * a later has_reported() over the same ids costs nothing.
	 *
	 * @since 1.9.3
	 * @param int    $reporter_id Reporting user.
	 * @param string $object_type 'post' or 'reply'.
	 * @param int[]  $object_ids  Object row IDs on the current page.
	 * @return array<int, bool> Map of object_id => true for reported rows.
	 */
	public static function reporter_flag_map( int $reporter_id, string $object_type, array $object_ids ): array {
		$object_ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		if ( $reporter_id <= 0 || empty( $object_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$rows         = static::db()->get_col(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT object_id FROM ' . static::table() . " WHERE reporter_id = %d AND object_type = %s AND object_id IN ({$placeholders})",
				$reporter_id,
				$object_type,
				...$object_ids
			)
		);

		$map = array();
		foreach ( $rows ?: array() as $oid ) {
			$map[ (int) $oid ] = true;
		}

		// Fill the memo, negatives included, so per-row reads over these ids
		// cost zero further queries.
		self::register_memo_reset();
		foreach ( $object_ids as $oid ) {
			self::$memo[ "{$reporter_id}|{$object_type}|{$oid}" ] = isset( $map[ $oid ] );
		}

		return $map;
	}

	/**
	 * Has this user already reported this object?
	 *
	 * The duplicate-report rule already existed server-side (the moderation
	 * controller answers a second report with 409 jetonomy_already_flagged),
	 * but the API published nothing a client could read it from - so the app
	 * tracked "reported" in local component state, lost it on refresh, drew an
	 * un-reported flag icon, and the re-tap silently 409'd (Basecamp
	 * 10202766654). Server-owned rule, server-published state.
	 *
	 * @since 1.9.3
	 * @param int    $reporter_id Reporting user.
	 * @param string $object_type 'post' or 'reply'.
	 * @param int    $object_id   Object row ID.
	 * @return bool
	 */
	public static function has_reported( int $reporter_id, string $object_type, int $object_id ): bool {
		if ( $reporter_id <= 0 || $object_id <= 0 ) {
			return false;
		}

		self::register_memo_reset();

		$key = "{$reporter_id}|{$object_type}|{$object_id}";
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		self::$memo[ $key ] = null !== static::find_by_reporter_and_object( $reporter_id, $object_type, $object_id );
		return self::$memo[ $key ];
	}

	/**
	 * Create a new flag report.
	 *
	 * Automatically sets status to 'pending' and created_at if absent.
	 *
	 * @param array $data Column data (object_type, object_id, reporter_id, reason, etc.).
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

		$id = static::insert( $data );

		// A new report invalidates any memoized "not reported" answer for this
		// request (e.g. the response shaped after the write).
		self::reset_memo();

		// Keep the post's denormalised open-flag counter in step (post targets,
		// pending only). Caller-agnostic so REST, Abilities, and CLI all maintain it.
		if ( $id && 'post' === ( $data['object_type'] ?? 'post' ) && 'pending' === ( $data['status'] ?? 'pending' ) ) {
			Post::increment_flag_count( (int) $data['object_id'], 1 );
		}

		return $id;
	}

	/**
	 * List all flags with status 'pending', newest first.
	 *
	 * @param int $limit  Max rows to return. 0 = unbounded (default,
	 *                    preserves pre-1.4.3 behaviour).
	 * @param int $offset Row offset for pagination. Ignored when $limit = 0.
	 * @return object[]
	 */
	public static function list_pending( int $limit = 0, int $offset = 0 ): array {
		$base = 'SELECT * FROM ' . static::table() . " WHERE status = 'pending' ORDER BY created_at DESC";
		if ( $limit > 0 ) {
			return static::db()->get_results(
				static::db()->prepare( $base . ' LIMIT %d OFFSET %d', $limit, max( 0, $offset ) )
			) ?: [];
		}
		return static::db()->get_results( $base ) ?: [];
	}

	/**
	 * Count flags with status 'pending'. Cheap alternative to count()
	 * on the full row set — adopted by callers that only need the
	 * number (pagination totals, badges).
	 *
	 * @return int
	 */
	public static function count_pending(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'pending'"
		);
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
		$flag = static::find( $id );
		if ( ! $flag ) {
			return false;
		}

		/*
		 * Compare-and-swap on the status we just read, instead of an
		 * unconditional update.
		 *
		 * Two moderators opening the same queue both see the flag as pending.
		 * Before 1.9.4 both writes landed: the second silently overwrote
		 * resolved_by and resolved_at, so the audit trail credited whoever
		 * clicked last and neither moderator saw an error. Model::update()
		 * could not surface it either — it returns `false !== $wpdb->update()`,
		 * and $wpdb->update() returns 0 (not false) for zero rows affected, so
		 * a no-op write reports success.
		 *
		 * Adding status to the WHERE makes the write atomic: the loser matches
		 * no row and gets false back, and every side effect below is already
		 * gated on $ok so nothing double-fires.
		 *
		 * Re-resolving a flag that is already decided stays supported — the CAS
		 * is against the status READ a moment ago, not hardcoded to 'pending'.
		 */
		$rows = static::db()->update(
			static::table(),
			[
				'status'      => $status,
				'resolved_by' => $resolved_by,
				'resolved_at' => now(),
			],
			[
				'id'     => $id,
				'status' => $flag->status,
			]
		);

		if ( false === $rows ) {
			return false;
		}

		if ( 0 === $rows ) {
			// Either someone else resolved it first, or the row already held
			// exactly these values. Only the former is a failure, so ask.
			$fresh = static::find( $id );
			if ( ! $fresh || $fresh->status !== $flag->status ) {
				return false;
			}
		}

		// Past this point the write succeeded and $flag is non-null (guarded
		// above), so the side effects below only need to test the PRIOR status.
		// A pending post-flag becoming resolved drops the post's open-flag count.
		if ( 'pending' === $flag->status && 'post' === $flag->object_type ) {
			Post::increment_flag_count( (int) $flag->object_id, -1 );
		}

		// Fairness contract: if a pending post-flag is dismissed (moderator
		// ruled the report invalid), restore the -10 the author lost at
		// report time. Without this, malicious or repeated false reports can
		// permanently damage an author's reputation even after a moderator
		// clears them, with no manual repair path short of CLI.
		//
		// Guarded on the prior status being 'pending' so re-dismissing an
		// already-dismissed flag doesn't double-restore. Self-flags (reporter
		// equals author) are excluded because the original report awarded no
		// deduction either — symmetry with the report-create path.
		if ( 'pending' === $flag->status
			&& 'dismissed' === $status
			&& 'post' === $flag->object_type
		) {
			$post = Post::find( (int) $flag->object_id );
			if ( $post ) {
				$author_id   = (int) $post->author_id;
				$reporter_id = (int) ( $flag->reporter_id ?? 0 );
				if ( $author_id > 0 && $author_id !== $reporter_id ) {
					\Jetonomy\Trust\Reputation::revoke( $author_id, 'post_reported' );
				}
			}
		}

		return true;
	}

	/**
	 * List flags filtered by status, newest first.
	 *
	 * @param string $status Row status value to filter by (e.g. 'pending', 'approved', 'dismissed').
	 * @param int    $limit  Maximum number of rows to return.
	 * @return object[]
	 */
	public static function list_by_status( string $status = 'pending', int $limit = 50, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$status,
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * List every flag regardless of status, newest first.
	 *
	 * @param int $limit  Maximum rows.
	 * @param int $offset Pagination offset.
	 * @return object[]
	 */
	public static function list_all( int $limit = 50, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Count flags with a given status ('' or 'all' counts every flag).
	 *
	 * Needed so a status-filtered list can paginate honestly — count_pending()
	 * only ever answers for 'pending'.
	 *
	 * @param string $status Status to count, or '' / 'all' for every flag.
	 */
	public static function count_by_status( string $status = 'pending' ): int {
		if ( '' === $status || 'all' === $status ) {
			return (int) static::db()->get_var( 'SELECT COUNT(*) FROM ' . static::table() );
		}

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE status = %s',
				$status
			)
		);
	}

	/**
	 * Find an existing flag by reporter and object (any status).
	 *
	 * @param int    $reporter_id Reporter user ID.
	 * @param string $object_type Object type (post, reply).
	 * @param int    $object_id   Object row ID.
	 * @return object|null Flag row or null.
	 */
	public static function find_by_reporter_and_object( int $reporter_id, string $object_type, int $object_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE reporter_id = %d AND object_type = %s AND object_id = %d LIMIT 1',
				$reporter_id,
				$object_type,
				$object_id
			)
		) ?: null;
	}

	/**
	 * List all flags filed against a single post (any status), newest first.
	 *
	 * Used by `GET /posts/{id}/flags` (1.4.1 A5) so a moderator viewing a
	 * specific post can see its flags without filtering the global queue.
	 * Row shape matches `list_pending()` so frontend can swap data sources
	 * without remapping fields.
	 *
	 * @param int $post_id Post row ID.
	 * @return object[]
	 */
	public static function find_for_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE object_type = 'post' AND object_id = %d ORDER BY created_at DESC",
				$post_id
			)
		) ?: [];
	}

	/**
	 * Resolve every other pending flag filed against the same object.
	 *
	 * Called after a moderator marks one flag as 'valid' and the underlying
	 * post or reply is trashed. Without this cascade, the queue keeps
	 * showing stale pending flags pointing at removed content, and a second
	 * moderator can re-action the same object minutes later.
	 *
	 * Excludes the originating flag (already resolved by the caller).
	 *
	 * @param string $object_type     'post' or 'reply'.
	 * @param int    $object_id       Object row ID.
	 * @param int    $resolved_by     Moderator user ID applying the cascade.
	 * @param string $status          New status ('valid' mirrors the originator).
	 * @param int    $exclude_flag_id Flag ID already resolved by the caller.
	 * @return int Number of sibling flags transitioned.
	 */
	public static function resolve_siblings_for(
		string $object_type,
		int $object_id,
		int $resolved_by,
		string $status,
		int $exclude_flag_id
	): int {
		if ( $object_id <= 0 || ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			return 0;
		}

		$db = static::db();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from static::table()
		$rows = $db->query(
			$db->prepare(
				'UPDATE ' . static::table() . " SET status = %s, resolved_by = %d, resolved_at = %s WHERE object_type = %s AND object_id = %d AND status = 'pending' AND id != %d",
				$status,
				$resolved_by,
				now(),
				$object_type,
				$object_id,
				$exclude_flag_id
			)
		);

		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * List pending flags on content that belongs to a single space.
	 *
	 * Resolves each flag's owning space via a LEFT JOIN chain:
	 *   post  flag → jt_posts
	 *   reply flag → jt_replies → jt_posts
	 * User flags are excluded — they have no space scope.
	 *
	 * @param int $space_id
	 * @return object[]
	 */
	public static function list_pending_in_space( int $space_id, int $limit = 0, int $offset = 0 ): array {
		return self::list_by_status_in_space( 'pending', $space_id, $limit, $offset );
	}

	/**
	 * Flags of a given status, scoped to a single space.
	 *
	 * The space-scoped sibling of {@see self::list_by_status()}. It exists because
	 * the per-space moderation screen could only ever list PENDING flags: the
	 * status was hardcoded here, so the Upheld/Dismissed chips on that screen
	 * filtered against a query that could never return them and always came back
	 * empty. Same dead UI the global screen had, for the same moderator — the
	 * global route got fixed and this one was never migrated
	 * (Basecamp 10092724637, 10092652706).
	 *
	 * @param string $status   'pending'|'valid'|'dismissed'|'all'.
	 * @param int    $space_id Space to scope to.
	 * @param int    $limit    0 = unbounded (kept for the legacy callers only).
	 * @param int    $offset
	 * @return object[]
	 */
	public static function list_by_status_in_space( string $status, int $space_id, int $limit = 0, int $offset = 0 ): array {
		if ( $space_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$flags_t   = \Jetonomy\table( 'flags' );
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );

		$where  = [];
		$params = [];

		if ( 'all' !== $status ) {
			$where[]  = 'f.status = %s';
			$params[] = $status;
		}
		$where[]  = '( ( f.object_type = %s AND p.space_id = %d ) OR ( f.object_type = %s AND rp.space_id = %d ) )';
		$params[] = 'post';
		$params[] = $space_id;
		$params[] = 'reply';
		$params[] = $space_id;

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are trusted; $where_sql is built from literals with placeholders.
		$base = "SELECT f.* FROM {$flags_t} f
			 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
			 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
			 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
			 {$where_sql}
			 ORDER BY f.created_at DESC";

		if ( $limit > 0 ) {
			$base    .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( $base, ...$params ) );

		return $rows ?: [];
	}

	/**
	 * Count flags of a given status in a space — the counterpart to
	 * {@see self::list_by_status_in_space()}.
	 *
	 * Counting the SAME status that was listed is the whole point: counting
	 * 'pending' while listing 'valid' makes has_more lie on every filter except
	 * the default, which is how a paginated queue silently loses rows.
	 *
	 * @param string $status   'pending'|'valid'|'dismissed'|'all'.
	 * @param int    $space_id
	 * @return int
	 */
	public static function count_by_status_in_space( string $status, int $space_id ): int {
		if ( $space_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$flags_t   = \Jetonomy\table( 'flags' );
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );

		$where  = [];
		$params = [];

		if ( 'all' !== $status ) {
			$where[]  = 'f.status = %s';
			$params[] = $status;
		}
		$where[]  = '( ( f.object_type = %s AND p.space_id = %d ) OR ( f.object_type = %s AND rp.space_id = %d ) )';
		$params[] = 'post';
		$params[] = $space_id;
		$params[] = 'reply';
		$params[] = $space_id;

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 {$where_sql}",
				...$params
			)
		);
	}

	/**
	 * Count pending flags scoped to a single space.
	 *
	 * @param int $space_id
	 * @return int
	 */
	public static function count_pending_in_space( int $space_id ): int {
		if ( $space_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		$flags_t   = \Jetonomy\table( 'flags' );
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 WHERE f.status = 'pending'
				   AND (
				     ( f.object_type = 'post'  AND p.space_id  = %d )
				     OR ( f.object_type = 'reply' AND rp.space_id = %d )
				   )",
				$space_id,
				$space_id
			)
		);
	}

	/**
	 * List pending flags on content across a set of spaces.
	 *
	 * Used by the scoped aggregate view (space mod visiting the admin-style
	 * queue without global cap).
	 *
	 * @param int[] $space_ids
	 * @return object[]
	 */
	public static function list_pending_in_spaces( array $space_ids, int $limit = 0, int $offset = 0 ): array {
		$space_ids = array_values( array_unique( array_filter( array_map( 'intval', $space_ids ) ) ) );
		if ( empty( $space_ids ) ) {
			return [];
		}

		global $wpdb;
		$flags_t      = \Jetonomy\table( 'flags' );
		$posts_t      = \Jetonomy\table( 'posts' );
		$replies_t    = \Jetonomy\table( 'replies' );
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		$params       = array_merge( $space_ids, $space_ids );

		$sql = "SELECT f.* FROM {$flags_t} f
			 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
			 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
			 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
			 WHERE f.status = 'pending'
			   AND (
			     ( f.object_type = 'post'  AND p.space_id  IN ({$placeholders}) )
			     OR ( f.object_type = 'reply' AND rp.space_id IN ({$placeholders}) )
			   )
			 ORDER BY f.created_at DESC";

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

		return $rows ?: [];
	}

	/**
	 * Count pending flags across the given set of spaces. Paired with
	 * list_pending_in_spaces() for pagination totals.
	 *
	 * @param int[] $space_ids
	 * @return int
	 */
	public static function count_pending_in_spaces( array $space_ids ): int {
		$space_ids = array_values( array_unique( array_filter( array_map( 'intval', $space_ids ) ) ) );
		if ( empty( $space_ids ) ) {
			return 0;
		}
		global $wpdb;
		$flags_t      = \Jetonomy\table( 'flags' );
		$posts_t      = \Jetonomy\table( 'posts' );
		$replies_t    = \Jetonomy\table( 'replies' );
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		$params       = array_merge( $space_ids, $space_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$flags_t} f
				 LEFT JOIN {$posts_t}   p  ON f.object_type = 'post'  AND f.object_id = p.id
				 LEFT JOIN {$replies_t} r  ON f.object_type = 'reply' AND f.object_id = r.id
				 LEFT JOIN {$posts_t}   rp ON r.post_id = rp.id
				 WHERE f.status = 'pending'
				   AND (
				     ( f.object_type = 'post'  AND p.space_id  IN ({$placeholders}) )
				     OR ( f.object_type = 'reply' AND rp.space_id IN ({$placeholders}) )
				   )",
				...$params
			)
		);
	}
}
