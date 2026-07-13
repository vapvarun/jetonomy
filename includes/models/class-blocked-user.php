<?php
/**
 * Blocked-user model.
 *
 * Foundation for the user-blocking safety primitive (Apple Guideline 1.2 —
 * UGC apps must let a member block an abusive member). Ships in FREE.
 *
 * This is step 1 of 2: schema + model + REST only. Nothing in the read
 * surfaces (feed, space listings, post detail, replies, search,
 * notifications, mentions, DM suggestions) calls this model yet — it is
 * inert until a follow-up change wires exclusion_sql() into those queries.
 *
 * @package Jetonomy
 * @since   1.7.1
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use function Jetonomy\now;

class BlockedUser extends Model {

	/**
	 * Above this many blocked IDs, exclusion_sql() switches from an inlined
	 * `NOT IN (1,2,3)` list to a `NOT IN (SELECT ...)` semi-join so the SQL
	 * text itself stays bounded no matter how many users someone has blocked.
	 */
	private const INLINE_CAP = 500;

	/**
	 * Per-request memo of blocked_ids() results, keyed by viewer_id.
	 *
	 * Guarantees "once per request" even without a persistent object cache —
	 * a single page render can call exclusion_sql() (and therefore
	 * blocked_ids()) 8+ times across feed/search/notifications enrichment.
	 *
	 * @var array<int,int[]>
	 */
	private static array $memo = [];

	protected static function table_name(): string {
		return 'blocked_users';
	}

	/**
	 * Block a user.
	 *
	 * @param int $blocker_id The user doing the blocking.
	 * @param int $blocked_id The user being blocked.
	 * @return true|WP_Error True on success (including "already blocked").
	 */
	public static function block( int $blocker_id, int $blocked_id ): bool|WP_Error {
		$blocker_id = absint( $blocker_id );
		$blocked_id = absint( $blocked_id );

		if ( $blocker_id === $blocked_id ) {
			return new WP_Error(
				'jetonomy_cannot_block_self',
				__( 'You cannot block yourself.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! get_userdata( $blocked_id ) ) {
			return new WP_Error(
				'jetonomy_not_found',
				__( 'User not found.', 'jetonomy' ),
				[ 'status' => 404 ]
			);
		}

		// Moderators must stay reachable — otherwise a member self-removes
		// from moderation surfaces (flag responses, moderator replies) by
		// blocking the moderator handling their case.
		if ( user_can( $blocked_id, 'manage_options' ) || user_can( $blocked_id, 'jetonomy_moderate' ) ) {
			return new WP_Error(
				'jetonomy_cannot_block_moderator',
				__( 'You cannot block a moderator.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static::table() is a trusted prefixed table name, not user input.
				'INSERT IGNORE INTO ' . static::table() . ' (blocker_id, blocked_id, created_at) VALUES (%d, %d, %s)',
				$blocker_id,
				$blocked_id,
				now()
			)
		);

		self::bust_cache( $blocker_id );

		/**
		 * Fires after a user blocks another user.
		 *
		 * @since 1.7.1
		 * @param int $blocker_id The user doing the blocking.
		 * @param int $blocked_id The user being blocked.
		 */
		do_action( 'jetonomy_user_blocked', $blocker_id, $blocked_id );

		return true;
	}

	/**
	 * Unblock a user.
	 *
	 * @param int $blocker_id The user removing the block.
	 * @param int $blocked_id The user being unblocked.
	 */
	public static function unblock( int $blocker_id, int $blocked_id ): bool {
		$blocker_id = absint( $blocker_id );
		$blocked_id = absint( $blocked_id );

		$deleted = false !== static::db()->delete(
			static::table(),
			[
				'blocker_id' => $blocker_id,
				'blocked_id' => $blocked_id,
			]
		);

		self::bust_cache( $blocker_id );

		/**
		 * Fires after a user unblocks another user.
		 *
		 * @since 1.7.1
		 * @param int $blocker_id The user removing the block.
		 * @param int $blocked_id The user being unblocked.
		 */
		do_action( 'jetonomy_user_unblocked', $blocker_id, $blocked_id );

		return $deleted;
	}

	/**
	 * One-way check: has $blocker_id blocked $blocked_id?
	 */
	public static function is_blocked( int $blocker_id, int $blocked_id ): bool {
		return null !== static::db()->get_var(
			static::db()->prepare(
				'SELECT 1 FROM ' . static::table() . ' WHERE blocker_id = %d AND blocked_id = %d LIMIT 1',
				$blocker_id,
				$blocked_id
			)
		);
	}

	/**
	 * EITHER-direction check — the DM gate predicate ONLY.
	 *
	 * Deliberately distinct from {@see self::is_blocked()}. Blocking is
	 * one-way for read-surface filtering (only the blocker stops seeing the
	 * blocked user's content), but a DM conversation is a two-party channel —
	 * if either side has blocked the other, new messages must not go through.
	 */
	public static function is_blocked_between( int $a, int $b ): bool {
		return null !== static::db()->get_var(
			static::db()->prepare(
				'SELECT 1 FROM ' . static::table() . ' WHERE (blocker_id = %d AND blocked_id = %d) OR (blocker_id = %d AND blocked_id = %d) LIMIT 1',
				$a,
				$b,
				$b,
				$a
			)
		);
	}

	/**
	 * The user IDs $viewer_id has blocked. THE primitive every read-surface
	 * exclusion is built on.
	 *
	 * Loaded once per request (self::$memo) and cached for 5 minutes
	 * (Cache::remember — correctly caches an empty array, so "no blocks", the
	 * ~99% case, is a cache HIT rather than a repeated miss).
	 *
	 * @param int $viewer_id Viewer user ID. <= 0 (guest) short-circuits to [].
	 * @return int[]
	 */
	public static function blocked_ids( int $viewer_id ): array {
		if ( $viewer_id <= 0 ) {
			return [];
		}

		if ( isset( self::$memo[ $viewer_id ] ) ) {
			return self::$memo[ $viewer_id ];
		}

		$ids = \Jetonomy\Cache::remember(
			"blocks:{$viewer_id}",
			function () use ( $viewer_id ) {
				$rows = static::db()->get_col(
					static::db()->prepare(
						'SELECT blocked_id FROM ' . static::table() . ' WHERE blocker_id = %d',
						$viewer_id
					)
				);
				return array_map( 'absint', $rows ?: [] );
			},
			300
		);

		/**
		 * Filter the set of user IDs blocked by $viewer_id.
		 *
		 * Lets Pro / 3rd-party code widen the exclusion set (e.g. a Pro
		 * "mute" feature) without touching this model.
		 *
		 * @since 1.7.1
		 * @param int[] $ids       Blocked user IDs.
		 * @param int   $viewer_id Viewer user ID.
		 */
		$ids = (array) apply_filters( 'jetonomy_blocked_user_ids', $ids, $viewer_id );
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		self::$memo[ $viewer_id ] = $ids;

		return $ids;
	}

	/**
	 * Paginated list of users $blocker_id has blocked.
	 *
	 * Returns raw rows (blocked_id, created_at) — callers batch-enrich user
	 * display data (e.g. Base_Controller::batch_load_users()) rather than
	 * resolving it here, so this model stays REST-shape-agnostic.
	 *
	 * @return object[]
	 */
	public static function list_by_blocker( int $blocker_id, int $limit = 20, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT blocked_id, created_at FROM ' . static::table() . ' WHERE blocker_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$blocker_id,
				$limit,
				$offset
			)
		) ?: [];
	}

	public static function count_by_blocker( int $blocker_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE blocker_id = %d',
				$blocker_id
			)
		);
	}

	/**
	 * Build a `NOT IN (...)` exclusion fragment for a read-surface query.
	 *
	 * Mirrors the proven contract of Space::content_visibility_sql() and
	 * Fulltext_Search::visibility_clause(): [ sql, params ], and returns
	 * `['', []]` when there's nothing to filter so every call site's
	 * `if ( '' !== $sql )` guard is a total no-op for guests and the ~99% of
	 * viewers with no blocks — zero added JOINs/WHEREs/params.
	 *
	 * The ids are INLINED with an EMPTY $params array by design: call sites
	 * (e.g. Search_Controller::search_posts()) splat several ordered
	 * fragments' params into one prepare() call, so a non-empty $params here
	 * would have to land in the exact right ordinal slot across ~15 call
	 * sites — one misordered array_merge is a silent wrong-data bug no test
	 * catches. The ids come from absint() on a bigint-unsigned PK column, so
	 * inlining them is injection-safe.
	 *
	 * @param int|null $viewer_id Viewer ID. Null = current user; pass 0 to force guest.
	 * @param string   $alias     Table alias without trailing dot (e.g. 'p'); '' if unaliased.
	 * @param string   $column    Column holding the author/user ID to exclude on.
	 * @return array{0:string,1:array} [SQL fragment, bind values]
	 */
	public static function exclusion_sql( ?int $viewer_id, string $alias = '', string $column = 'author_id' ): array {
		$viewer_id = $viewer_id ?? get_current_user_id();
		if ( $viewer_id <= 0 ) {
			return [ '', [] ];
		}

		$ids = self::blocked_ids( $viewer_id );
		if ( empty( $ids ) ) {
			return [ '', [] ];
		}

		$col = ( '' !== $alias ? $alias . '.' : '' ) . $column;

		if ( count( $ids ) > self::INLINE_CAP ) {
			return [
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $col is built from a fixed alias/column, not user input; the %d placeholder still carries $viewer_id.
				"{$col} NOT IN (SELECT blocked_id FROM " . static::table() . ' WHERE blocker_id = %d)',
				[ $viewer_id ],
			];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are absint()'d bigint-unsigned PK values from blocked_ids(); inlining (instead of %d placeholders) keeps $params empty so this fragment never shifts the ordinal position of a caller's own prepare() params.
		return [ "{$col} NOT IN (" . implode( ',', $ids ) . ')', [] ];
	}

	/**
	 * Clear the per-request memo + persistent cache for a blocker.
	 *
	 * Blocking is one-way, so only the blocker's own cache entry ever
	 * changes — exactly one key per mutation.
	 */
	private static function bust_cache( int $blocker_id ): void {
		\Jetonomy\Cache::delete( "blocks:{$blocker_id}" );
		unset( self::$memo[ $blocker_id ] );
	}
}
