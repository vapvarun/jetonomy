<?php
/**
 * Space purge — every row a space owns, in one declared map.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Removes a space and everything hanging off it.
 *
 * One map, three consumers
 * ------------------------
 * {@see relations()} is the single declaration of how a space is referenced
 * across the schema. The purge, the orphan discovery in {@see Space_Backfill},
 * and the "what would this remove" report all derive from it. There is
 * deliberately no second list: a hand-maintained twin is how `ai_log` came to
 * be missing from the user purge for two releases ({@see Privacy_Backfill}),
 * and the space graph is three times the size, so the same mistake here would
 * be three times as quiet.
 *
 * Why a purge exists before anything calls it
 * -------------------------------------------
 * Today `Space::delete()` deletes the row and nothing else, so every space
 * ever deleted left its posts, replies, members, join requests and
 * notifications behind, pointing at an id that no longer resolves. This class
 * is how that damage gets swept up (via Space_Backfill), and it is deliberately
 * NOT wired into `Space::delete()` yet.
 *
 * That ordering is the point. Deletion is due to default to TRANSFERRING a
 * space to its next moderator rather than destroying it, with a permanent
 * purge as an opt-in the site owner controls. Wiring the cascade in first
 * would turn today's recoverable orphans into real data loss — other members'
 * topics and replies — with no safe default in place to choose instead. So the
 * engine lands first and stays unarmed; the delete flow adopts it once transfer
 * exists.
 *
 * Resolution order
 * ----------------
 * Post and reply ids are resolved BEFORE anything is deleted, because the rows
 * that identify them are themselves in the purge set. This works identically
 * for a live space and an already-orphaned one: `posts.space_id` still holds
 * the id whether or not the parent `spaces` row survives, which is what lets
 * the backfill replay this same body against damage done years ago.
 */
final class Space_Purge {

	/** Chunk size for `IN (...)` deletes, so a 50k-topic space never builds one enormous statement. */
	private const CHUNK = 500;

	/** One group per plugin, so every Jetonomy job is observable together. */
	private const AS_GROUP = 'jetonomy';

	/** Continuation hook. Carries the space id; re-enqueued until the space drains. */
	public const BATCH_HOOK = 'jetonomy_space_purge_batch';

	/**
	 * Listen for orphan remediation.
	 *
	 * The backfill fires `jetonomy_purge_orphan_space` per id rather than
	 * calling this class directly, so Pro (and third-party code holding
	 * space-scoped rows) cleans up through the same signal instead of shipping
	 * a parallel sweeper.
	 */
	public static function register(): void {
		add_action( 'jetonomy_purge_orphan_space', [ self::class, 'on_purge_orphan_space' ] );

		// Action Scheduler fires this like any other action, so a plain
		// add_action is all the wiring the batch needs.
		add_action( self::BATCH_HOOK, [ self::class, 'run_batch' ] );
	}

	/**
	 * Start a purge that survives a 50k-topic space.
	 *
	 * The synchronous purge() drains everything in one request, which is fine
	 * for an orphan sweep of a handful of rows and completely wrong for a real
	 * community space - a space with 50k topics and their replies cannot be
	 * deleted inside one PHP request (Basecamp 10119343634).
	 *
	 * Hands the work to Action Scheduler when it is available, which it always
	 * is here because the plugin bundles it (libs/action-scheduler). The inline
	 * fallback exists for the case where the library failed to load rather than
	 * as a supported path - draining synchronously is exactly what this method
	 * is for avoiding, so it is a last resort, not an alternative.
	 *
	 * Idempotent: a space already queued is not queued twice.
	 *
	 * @param int $space_id Space to purge.
	 * @return bool True when the work was queued, false when it ran inline.
	 */
	public static function queue( int $space_id ): bool {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 ) {
			return false;
		}

		$args = [ 'space_id' => $space_id ];

		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( as_has_scheduled_action( self::BATCH_HOOK, $args, self::AS_GROUP ) ) {
				return true;
			}
			as_enqueue_async_action( self::BATCH_HOOK, $args, self::AS_GROUP );
			return true;
		}

		self::purge( $space_id );
		return false;
	}

	/**
	 * One batch. Deletes a bounded slice, then re-queues itself if work remains.
	 *
	 * @param mixed $space_id Space id, as passed by Action Scheduler.
	 */
	public static function run_batch( $space_id ): void {
		$space_id = (int) ( is_array( $space_id ) ? ( $space_id['space_id'] ?? 0 ) : $space_id );
		if ( $space_id <= 0 ) {
			return;
		}

		$step = self::purge_step( $space_id );

		// Counts accumulate across batches so the completion signal reports the
		// whole purge, not whichever slice happened to finish it. Short TTL
		// because it is progress state, not data: losing it costs an accurate
		// count in one hook payload, never a row.
		$tally_key = 'jetonomy_purge_tally_' . $space_id;
		$tally     = (array) get_transient( $tally_key );
		foreach ( $step['removed'] as $key => $n ) {
			$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + $n;
		}

		if ( $step['done'] ) {
			delete_transient( $tally_key );

			/** This action is documented in purge(). */
			do_action( 'jetonomy_space_purged', $space_id, $tally );
			return;
		}

		set_transient( $tally_key, $tally, DAY_IN_SECONDS );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, [ 'space_id' => $space_id ], self::AS_GROUP );
			return;
		}

		// AS vanished mid-purge (deactivated between batches). Finish inline
		// rather than leaving a half-deleted space behind.
		self::purge( $space_id );
	}

	/**
	 * Clear any queued purge work. Called on deactivation.
	 */
	public static function clear_scheduled(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, [], self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}

	/**
	 * Action adapter for `jetonomy_purge_orphan_space`.
	 *
	 * The purge() method returns its per-table counts because callers want
	 * them; an action callback must not return anything. Rather than make
	 * purge() void and lose the report, the hook gets a void adapter.
	 *
	 * @param mixed $space_id Space id, as passed by do_action().
	 */
	public static function on_purge_orphan_space( $space_id ): void {
		self::purge( (int) $space_id );
	}

	/**
	 * How every table in the schema refers to a space.
	 *
	 * `ref` names which id set the column's values belong to — a space id, a
	 * post id, or a reply id — which is what lets one declaration drive both
	 * the purge (delete WHERE column IN <set>) and orphan discovery (find
	 * values with no surviving parent).
	 *
	 * `where` narrows polymorphic tables to the rows of the relevant type.
	 * `self` marks the space row itself, which is deleted last.
	 *
	 * @return array<int,array{table:string,column:string,ref:string,where:string,self:bool}>
	 */
	public static function relations(): array {
		$rel = [
			// Direct space references.
			[
				'table'  => table( 'posts' ),
				'column' => 'space_id',
				'ref'    => 'space',
			],
			[
				'table'  => table( 'space_members' ),
				'column' => 'space_id',
				'ref'    => 'space',
			],
			[
				'table'  => table( 'join_requests' ),
				'column' => 'space_id',
				'ref'    => 'space',
			],
			[
				'table'  => table( 'access_rules' ),
				'column' => 'space_id',
				'ref'    => 'space',
			],
			[
				'table'  => table( 'invite_links' ),
				'column' => 'space_id',
				'ref'    => 'space',
			],
			[
				'table'  => table( 'restrictions' ),
				'column' => 'space_id',
				'ref'    => 'space',
			],

			// Polymorphic rows pointing AT the space.
			[
				'table'  => table( 'notifications' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			],
			[
				'table'  => table( 'subscriptions' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			],
			[
				'table'  => table( 'activity_log' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			],

			// One hop out: rows hanging off the space's topics.
			[
				'table'  => table( 'replies' ),
				'column' => 'post_id',
				'ref'    => 'post',
			],
			[
				'table'  => table( 'post_tags' ),
				'column' => 'post_id',
				'ref'    => 'post',
			],
			[
				'table'  => table( 'read_status' ),
				'column' => 'post_id',
				'ref'    => 'post',
			],
			[
				'table'  => table( 'bookmarks' ),
				'column' => 'post_id',
				'ref'    => 'post',
			],
		];

		// Polymorphic rows pointing at those topics, and at their replies. Same
		// tables, two id sets — declared as a loop so a table added to one type
		// can never be forgotten for the other, which is the drift this whole
		// class is built to avoid.
		foreach ( [ 'post', 'reply' ] as $ref ) {
			foreach ( [ 'notifications', 'subscriptions', 'activity_log', 'votes', 'flags', 'attachments', 'revisions' ] as $t ) {
				// subscriptions is ENUM('space','post') — it cannot hold a reply.
				if ( 'reply' === $ref && 'subscriptions' === $t ) {
					continue;
				}
				$rel[] = [
					'table'  => table( $t ),
					'column' => 'object_id',
					'ref'    => $ref,
					'where'  => "object_type = '" . $ref . "'",
				];
			}
		}

		// The space row itself, deleted last.
		$rel[] = [
			'table'  => table( 'spaces' ),
			'column' => 'id',
			'ref'    => 'space',
			'self'   => true,
		];

		/**
		 * Every table that holds a reference to a space, its topics, or their
		 * replies.
		 *
		 * Pro adds its own `jt_pro_*` tables here rather than shipping a
		 * parallel purge — free owns the engine, Pro contributes its tables.
		 * Mirrors `jetonomy_privacy_orphan_columns`.
		 *
		 * @param array<int,array{table:string,column:string,ref:string,where?:string,self?:bool}> $rel Relations.
		 */
		$rel = apply_filters( 'jetonomy_space_relations', $rel );

		$clean = [];
		foreach ( (array) $rel as $r ) {
			if ( empty( $r['table'] ) || empty( $r['column'] ) || empty( $r['ref'] ) ) {
				continue;
			}
			if ( ! in_array( $r['ref'], [ 'space', 'post', 'reply' ], true ) ) {
				continue; // Unknown id set — we have no way to resolve it.
			}
			$clean[] = [
				'table'  => (string) $r['table'],
				'column' => sanitize_key( (string) $r['column'] ),
				'ref'    => (string) $r['ref'],
				'where'  => (string) ( $r['where'] ?? '' ),
				'self'   => (bool) ( $r['self'] ?? false ),
			];
		}
		return $clean;
	}

	/**
	 * Delete a space and every row that references it.
	 *
	 * Idempotent: a second call finds nothing left to remove and reports zeros.
	 * Safe on an already-orphaned space (the `spaces` row gone, its children
	 * still present), which is exactly the backfill's case.
	 *
	 * @param int $space_id Space id.
	 * @return array<string,int> Rows removed, keyed "table.column" (only non-zero entries).
	 */
	/**
	 * Delete a bounded slice of a space, deepest level first.
	 *
	 * The unit of work is deliberately a slice of REPLIES, then a slice of
	 * POSTS, then the space itself - never "everything". purge() loads every
	 * post id and every reply id into memory and deletes them in one request,
	 * which is correct for an orphan sweep of a few rows and impossible for a
	 * 50k-topic space.
	 *
	 * Depth order matters and is not cosmetic: votes and flags hang off posts
	 * and replies, so a post cannot go before the rows pointing at it, or the
	 * next pass has no way to find them. Same reason the space row goes last.
	 *
	 * Idempotent and resumable. Each call re-derives what is left from the
	 * database rather than carrying a cursor, so a batch that dies mid-run
	 * loses nothing and the next one picks up exactly where the data is.
	 *
	 * @param int $space_id Space being purged.
	 * @param int $limit    Max parent rows to process this call.
	 * @return array{done:bool,removed:array<string,int>}
	 */
	public static function purge_step( int $space_id, int $limit = self::CHUNK ): array {
		$space_id = (int) $space_id;
		$limit    = max( 1, $limit );
		$removed  = [];

		if ( $space_id <= 0 ) {
			return [
				'done'    => true,
				'removed' => $removed,
			];
		}

		// The unit of work is a slice of TOPICS, because that is what a space
		// has tens of thousands of. Everything below a topic - its replies, and
		// everything pointing at either - goes with the slice, so the depth
		// order purge() relies on still holds within the batch.
		$post_slice = self::post_ids( $space_id, $limit );

		if ( $post_slice ) {
			// Ids first, exactly as purge() does: the rows that identify
			// replies are themselves in the delete set.
			$reply_slice = self::reply_ids( $post_slice );

			foreach ( self::relations() as $r ) {
				if ( 'reply' === $r['ref'] && $reply_slice ) {
					$n = self::delete_where_in( $r, $reply_slice );
					if ( $n > 0 ) {
						$removed[ $r['table'] . '.' . $r['column'] . ':reply' ] = $n;
					}
				}
			}

			// Includes replies.post_id, so the reply rows go here.
			foreach ( self::relations() as $r ) {
				if ( 'post' === $r['ref'] ) {
					$n = self::delete_where_in( $r, $post_slice );
					if ( $n > 0 ) {
						$removed[ $r['table'] . '.' . $r['column'] . ':post' ] = $n;
					}
				}
			}

			// The topic rows themselves. posts is declared as a space-ref
			// relation (posts.space_id), which cannot express "these ids", so
			// the slice is deleted through the same helper with an id-keyed
			// relation rather than hand-rolled SQL.
			$n = self::delete_where_in(
				[
					'table'  => table( 'posts' ),
					'column' => 'id',
					'ref'    => 'post',
					'where'  => '',
					'self'   => false,
				],
				$post_slice
			);
			if ( $n > 0 ) {
				$removed[ table( 'posts' ) . '.id:post' ] = $n;
			}

			return [
				'done'    => false,
				'removed' => $removed,
			];
		}

		// No topics left. Space-scoped rows, then the space row, then counters.
		$space       = Models\Space::find( $space_id );
		$slug        = $space->slug ?? null;
		$category_id = (int) ( $space->category_id ?? 0 );

		foreach ( self::relations() as $r ) {
			if ( 'space' !== $r['ref'] || $r['self'] ) {
				continue;
			}
			$n = self::delete_where_in( $r, [ $space_id ] );
			if ( $n > 0 ) {
				$removed[ $r['table'] . '.' . $r['column'] . ':space' ] = $n;
			}
		}

		// The space row last, so an interrupted purge leaves the space
		// discoverable and the next pass finishes the job.
		foreach ( self::relations() as $r ) {
			if ( ! $r['self'] ) {
				continue;
			}
			$n = self::delete_where_in( $r, [ $space_id ] );
			if ( $n > 0 ) {
				$removed[ $r['table'] . '.' . $r['column'] ] = $n;
			}
		}

		// Only when the space row was actually still there — an orphan sweep
		// must not decrement a counter the original delete already adjusted.
		if ( $category_id > 0 && $space ) {
			Models\Category::increment_space_count( $category_id, -1 );
		}

		Models\Space::bust_cache( $space_id, $slug );

		return [
			'done'    => true,
			'removed' => $removed,
		];
	}

	public static function purge( int $space_id ): array {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 ) {
			return [];
		}

		// Drains by repeatedly calling the SAME bounded step the batched path
		// uses, rather than carrying a second full-sweep implementation that
		// could drift from it. Callers who cannot afford a long request should
		// use queue(); this one runs to completion.
		$removed = [];
		do {
			$step = self::purge_step( $space_id );
			foreach ( $step['removed'] as $key => $n ) {
				$removed[ $key ] = ( $removed[ $key ] ?? 0 ) + $n;
			}
		} while ( ! $step['done'] );

		/**
		 * A space and everything it owned has been removed.
		 *
		 * @param int               $space_id Space id.
		 * @param array<string,int> $removed  Rows removed per table.column:ref.
		 */
		do_action( 'jetonomy_space_purged', $space_id, $removed );

		return $removed;
	}

	/**
	 * Topic ids in a space.
	 *
	 * Read straight from `posts.space_id` rather than through the model, so it
	 * still resolves when the parent `spaces` row is already gone.
	 *
	 * @param int $space_id Space id.
	 * @return int[]
	 */
	private static function post_ids( int $space_id, int $limit = 0 ): array {
		global $wpdb;
		$posts = table( 'posts' );
		if ( ! self::table_exists( $posts ) ) {
			return [];
		}

		// $limit > 0 is the batched path: take a slice instead of every id. A
		// 50k-topic space would otherwise materialise 50k ids just to start.
		$sql = $limit > 0
			? $wpdb->prepare( "SELECT id FROM {$posts} WHERE space_id = %d ORDER BY id ASC LIMIT %d", $space_id, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT id FROM {$posts} WHERE space_id = %d", $space_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Reply ids belonging to the given topics.
	 *
	 * @param int[] $post_ids Topic ids.
	 * @return int[]
	 */
	private static function reply_ids( array $post_ids, int $limit = 0 ): array {
		global $wpdb;
		$replies = table( 'replies' );
		if ( ! $post_ids || ! self::table_exists( $replies ) ) {
			return [];
		}

		$out = [];
		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$tail = $limit > 0 ? ' ORDER BY id ASC LIMIT ' . (int) ( $limit - count( $out ) ) : '';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$out = array_merge( $out, (array) $wpdb->get_col( "SELECT id FROM {$replies} WHERE post_id IN ({$in}){$tail}" ) );

			// Slice full - a single topic can hold more replies than the whole
			// batch budget, so stop rather than walking the remaining chunks.
			if ( $limit > 0 && count( $out ) >= $limit ) {
				break;
			}
		}
		return array_map( 'intval', $out );
	}

	/**
	 * Delete a relation's rows for a set of ids, in chunks.
	 *
	 * @param array{table:string,column:string,where:string} $rel Relation.
	 * @param int[]                                          $ids Id set.
	 * @return int Rows removed.
	 */
	private static function delete_where_in( array $rel, array $ids ): int {
		global $wpdb;

		if ( ! $ids || ! self::table_exists( $rel['table'] ) ) {
			return 0;
		}

		$tbl   = $rel['table'];
		$col   = $rel['column'];
		$extra = '' !== $rel['where'] ? ' AND ( ' . $rel['where'] . ' )' : '';
		$n     = 0;

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n += (int) $wpdb->query( "DELETE FROM {$tbl} WHERE {$col} IN ({$in}){$extra}" );
		}
		return $n;
	}

	/** Does a table exist? Pro tables are absent when the extension never ran. */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
