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

	/** Continuation hook. Carries the space id; re-enqueued until the space drains. */
	public const BATCH_HOOK = 'jetonomy_space_purge_batch';

	/**
	 * Why the last purge() stopped early, or '' when it finished.
	 *
	 * @var string
	 */
	private static string $last_error = '';

	/**
	 * Listen for orphan remediation.
	 *
	 * The backfill fires `jetonomy_purge_orphan_space` per id rather than
	 * calling this class directly, so Pro (and third-party code holding
	 * space-scoped rows) cleans up through the same signal instead of shipping
	 * a parallel sweeper.
	 */
	public static function register(): void {
		add_action( 'jetonomy_purge_orphan_space', array( self::class, 'on_purge_orphan_space' ) );

		// Action Scheduler fires this like any other action, so a plain
		// add_action is all the wiring the batch needs.
		add_action( self::BATCH_HOOK, array( self::class, 'run_batch' ) );
	}

	/**
	 * Start a purge that survives a 50k-topic space.
	 *
	 * The synchronous purge() drains everything in one request, which is fine
	 * for an orphan sweep of a handful of rows and completely wrong for a real
	 * community space - a space with 50k topics and their replies cannot be
	 * deleted inside one PHP request.
	 *
	 * Work always starts in this request, so a host whose Action Scheduler
	 * queue never runs still makes progress:
	 *   - up to CHUNK (500) topics: the whole space is deleted before the
	 *     response is sent;
	 *   - larger: the space is locked (members can no longer post), the first
	 *     batch is deleted here and the rest is queued; run_batch() finishes
	 *     inline if it cannot hand off.
	 * A database error stops the purge with the space row intact and retries
	 * a minute later, so a failed read can never orphan replies.
	 *
	 * Idempotent: a space already queued is left to the runner.
	 *
	 * @param int $space_id Space to purge.
	 * @return bool True when the purge started (or was already under way).
	 */
	public static function queue( int $space_id ): bool {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 ) {
			return false;
		}

		$args = array( 'space_id' => $space_id );

		// Already handed off by an earlier request - let the runner have it
		// rather than deleting the same slice from two directions.
		if ( Queue::is_queued( self::BATCH_HOOK, $args ) ) {
			return true;
		}

		// Is this space actually big enough to need batching? Ask for one slice
		// more than a batch holds: the answer is "no" for all but the largest
		// spaces, and it costs a bounded id lookup rather than a COUNT(*) over
		// a 50k-row table.
		try {
			$oversized = count( self::post_ids( $space_id, self::CHUNK + 1 ) ) > self::CHUNK;
		} catch ( \RuntimeException $e ) {
			// Cannot even size the space; leave it intact and try again shortly.
			self::retry_later( $space_id );
			return true;
		}

		if ( ! $oversized ) {
			// Deleted here, now, in this request. A space this size is well
			// inside what one request can drain, and doing it inline means the
			// operator's next page load shows the truth instead of a space that
			// was reported deleted and is still sitting in the list.
			self::purge( $space_id );
			if ( '' !== self::$last_error ) {
				self::retry_later( $space_id );
			}
			return true;
		}

		// Genuinely large: members must stop posting into a space that is
		// being deleted, so lock it, then make the first batch of progress in
		// this request and hand the rest to the queue.
		Models\Space::update( $space_id, array( 'status' => 'locked' ) );
		$first = self::purge_step( $space_id );
		if ( ! empty( $first['error'] ) ) {
			self::retry_later( $space_id );
			return true;
		}
		set_transient( 'jetonomy_purge_tally_' . $space_id, $first['removed'], DAY_IN_SECONDS );
		set_transient( 'jetonomy_purge_authors_' . $space_id, $first['authors'], DAY_IN_SECONDS );

		// Only trust the hand-off if the store accepted the job.
		if ( Queue::async( self::BATCH_HOOK, $args, false ) ) {
			return true;
		}

		// No usable queue. Draining a huge space in one request is exactly what
		// this method exists to avoid, so this is a bad outcome - but it is the
		// better of the two available ones. Not deleting the space at all,
		// while telling the operator it is gone, is worse.
		self::purge( $space_id );

		return true;
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
		if ( ! empty( $step['error'] ) ) {
			// The database refused a read. Nothing was deleted past it; try again shortly.
			self::retry_later( $space_id );
			return;
		}

		// Counts accumulate across batches so the completion signal reports the
		// whole purge, not whichever slice happened to finish it. Short TTL
		// because it is progress state, not data: losing it costs an accurate
		// count in one hook payload, never a row.
		$tally_key   = 'jetonomy_purge_tally_' . $space_id;
		$authors_key = 'jetonomy_purge_authors_' . $space_id;
		$tally       = (array) get_transient( $tally_key );
		$authors     = (array) get_transient( $authors_key );

		foreach ( $step['removed'] as $key => $n ) {
			$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + $n;
		}
		$authors = array_values( array_unique( array_merge( $authors, $step['authors'] ) ) );

		if ( $step['done'] ) {
			delete_transient( $tally_key );
			delete_transient( $authors_key );

			// Counters last: the rows are gone, so a recount now sees the truth.
			self::recount_authors( $authors );

			/** This action is documented in purge(). */
			do_action( 'jetonomy_space_purged', $space_id, $tally );
			return;
		}

		set_transient( $tally_key, $tally, DAY_IN_SECONDS );
		set_transient( $authors_key, $authors, DAY_IN_SECONDS );

		// The return value is load-bearing: as_enqueue_async_action() answers 0
		// when the store refuses the job, and treating that as a successful
		// hand-off is what strands a half-deleted space - its topics gone, its
		// row still listed, and nothing scheduled to finish the job.
		if ( Queue::async( self::BATCH_HOOK, array( 'space_id' => $space_id ), false ) ) {
			return;
		}

		// AS vanished mid-purge (deactivated between batches), or refused the
		// job. Finish inline rather than leaving a half-deleted space behind.
		self::purge( $space_id );
	}

	/**
	 * Schedule another attempt after a database error, a minute out so a
	 * struggling database is not hammered. If even that fails, the next delete
	 * request or orphan sweep resumes it, because the space row stays.
	 *
	 * @param int $space_id Space being purged.
	 */
	private static function retry_later( int $space_id ): void {
		Queue::at( time() + MINUTE_IN_SECONDS, self::BATCH_HOOK, array( 'space_id' => $space_id ) );
	}

	/**
	 * Rebuild post/reply totals for members who lost content to a purge.
	 *
	 * Deleting a space silently invalidates every affected author's
	 * post_count and reply_count, which are denormalised counters the profile
	 * and leaderboard read directly - so without this a purge leaves members
	 * credited for topics that no longer exist.
	 *
	 * Scoped to the affected members via Recount::for_users() rather than
	 * Recount::run( 'users' ), which rewrites every profile row and flushes the
	 * whole cache.
	 *
	 * @param int[] $authors Members whose content was removed.
	 */
	private static function recount_authors( array $authors ): void {
		$authors = array_values( array_unique( array_filter( array_map( 'intval', $authors ) ) ) );
		if ( $authors ) {
			Recount::for_users( $authors );
		}
	}

	/**
	 * Clear any queued purge work. Called on deactivation.
	 */
	public static function clear_scheduled(): void {
		Queue::cancel( self::BATCH_HOOK );
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
		$rel = array(
			// Direct space references.
			array(
				'table'  => table( 'posts' ),
				'column' => 'space_id',
				'ref'    => 'space',
			),
			array(
				'table'  => table( 'space_members' ),
				'column' => 'space_id',
				'ref'    => 'space',
			),
			array(
				'table'  => table( 'join_requests' ),
				'column' => 'space_id',
				'ref'    => 'space',
			),
			array(
				'table'  => table( 'access_rules' ),
				'column' => 'space_id',
				'ref'    => 'space',
			),
			array(
				'table'  => table( 'invite_links' ),
				'column' => 'space_id',
				'ref'    => 'space',
			),
			array(
				'table'  => table( 'restrictions' ),
				'column' => 'space_id',
				'ref'    => 'space',
			),

			// Polymorphic rows pointing AT the space.
			array(
				'table'  => table( 'notifications' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			),
			array(
				'table'  => table( 'subscriptions' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			),
			array(
				'table'  => table( 'activity_log' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			),

			// One hop out: rows hanging off the space's topics.
			array(
				'table'  => table( 'replies' ),
				'column' => 'post_id',
				'ref'    => 'post',
			),
			array(
				'table'  => table( 'post_tags' ),
				'column' => 'post_id',
				'ref'    => 'post',
			),
			array(
				'table'  => table( 'read_status' ),
				'column' => 'post_id',
				'ref'    => 'post',
			),
			array(
				'table'  => table( 'bookmarks' ),
				'column' => 'post_id',
				'ref'    => 'post',
			),
		);

		// Polymorphic rows pointing at those topics, and at their replies. Same
		// tables, two id sets — declared as a loop so a table added to one type
		// can never be forgotten for the other, which is the drift this whole
		// class is built to avoid.
		foreach ( array( 'post', 'reply' ) as $ref ) {
			foreach ( array( 'notifications', 'subscriptions', 'activity_log', 'votes', 'flags', 'attachments', 'revisions' ) as $t ) {
				// subscriptions is ENUM('space','post') — it cannot hold a reply.
				if ( 'reply' === $ref && 'subscriptions' === $t ) {
					continue;
				}
				$rel[] = array(
					'table'  => table( $t ),
					'column' => 'object_id',
					'ref'    => $ref,
					'where'  => "object_type = '" . $ref . "'",
				);
			}
		}

		// The space row itself, deleted last.
		$rel[] = array(
			'table'  => table( 'spaces' ),
			'column' => 'id',
			'ref'    => 'space',
			'self'   => true,
		);

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

		$clean = array();
		foreach ( (array) $rel as $r ) {
			if ( empty( $r['table'] ) || empty( $r['column'] ) || empty( $r['ref'] ) ) {
				continue;
			}
			if ( ! in_array( $r['ref'], array( 'space', 'post', 'reply' ), true ) ) {
				continue; // Unknown id set — we have no way to resolve it.
			}
			$clean[] = array(
				'table'  => (string) $r['table'],
				'column' => sanitize_key( (string) $r['column'] ),
				'ref'    => (string) $r['ref'],
				'where'  => (string) ( $r['where'] ?? '' ),
				'self'   => (bool) ( $r['self'] ?? false ),
			);
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
	 * @return array{done:bool,removed:array<string,int>,authors:int[],error?:string}
	 */
	public static function purge_step( int $space_id, int $limit = self::CHUNK ): array {
		try {
			return self::run_step( (int) $space_id, $limit );
		} catch ( \RuntimeException $e ) {
			// Stop here: nothing past a failed read is trustworthy, and the
			// space row must survive so the purge can be resumed.
			return array(
				'done'    => false,
				'removed' => array(),
				'authors' => array(),
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * The body of purge_step(), which turns a database error into a result.
	 *
	 * @param int $space_id Space being purged.
	 * @param int $limit    Max parent rows to process this call.
	 * @return array{done:bool,removed:array<string,int>,authors:int[]}
	 * @throws \RuntimeException On a database error.
	 */
	private static function run_step( int $space_id, int $limit ): array {
		$space_id = (int) $space_id;
		$limit    = max( 1, $limit );
		$removed  = array();

		if ( $space_id <= 0 ) {
			return array(
				'done'    => true,
				'removed' => $removed,
				'authors' => array(),
			);
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

			// Authors BEFORE the rows go, so their post_count / reply_count can
			// be rebuilt once the purge finishes. Collected per slice and
			// deduped by the caller, which keeps the recount to the members who
			// actually lost content instead of every profile on the site.
			$authors = self::author_ids( $post_slice, $reply_slice );

			// Same for tags: the post_tags rows go in bulk below, so read which
			// tags they touch first and rebuild just those counts afterwards.
			$tag_ids = Models\Tag::ids_for_posts( $post_slice );

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
				array(
					'table'  => table( 'posts' ),
					'column' => 'id',
					'ref'    => 'post',
					'where'  => '',
					'self'   => false,
				),
				$post_slice
			);
			if ( $n <= 0 ) {
				throw new \RuntimeException( 'Topic rows could not be deleted.' );
			}
			$removed[ table( 'posts' ) . '.id:post' ] = $n;

			Models\Tag::recount( $tag_ids );

			// The rows went in bulk, so Post::delete() / Reply::delete() never
			// ran and their delete hooks never told the host community to drop
			// its bell rows. Same signal, for the ids this slice removed.
			foreach ( $reply_slice as $reply_id ) {
				/**
				 * Fires after a purged reply or post is removed in bulk, so the host community can drop its notification rows.
				 *
				 * @since 2.0.1
				 *
				 * @param string $object_type Object type: 'reply' or 'post'.
				 * @param int    $object_id   Reply or post ID.
				 */
				do_action( 'jetonomy_community_notification_removed', 'reply', (int) $reply_id );
			}
			foreach ( $post_slice as $post_id ) {
				do_action( 'jetonomy_community_notification_removed', 'post', (int) $post_id );
			}

			return array(
				'done'    => false,
				'removed' => $removed,
				'authors' => $authors,
			);
		}

		// No topics left. Space-scoped rows, then the space row, then counters.
		$space       = Models\Space::find( $space_id );
		$slug        = $space->slug ?? null;
		$category_id = (int) ( $space->category_id ?? 0 );

		foreach ( self::relations() as $r ) {
			if ( 'space' !== $r['ref'] || $r['self'] ) {
				continue;
			}
			$n = self::delete_where_in( $r, array( $space_id ) );
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
			$n = self::delete_where_in( $r, array( $space_id ) );
			if ( $n > 0 ) {
				$removed[ $r['table'] . '.' . $r['column'] ] = $n;
			}
		}

		// Only a space that still counted in its category: an orphan sweep has no
		// row, and a locked (large purge) or archived space was already taken off
		// the count by Space::update(), which counts active spaces only.
		if ( $category_id > 0 && $space && 'active' === ( $space->status ?? '' ) ) {
			Models\Category::increment_space_count( $category_id, -1 );
		}

		Models\Space::bust_cache( $space_id, $slug );

		// Space-level notifications (join requests and the like) go with it.
		do_action( 'jetonomy_community_notification_removed', 'space', $space_id );

		return array(
			'done'    => true,
			'removed' => $removed,
			'authors' => array(),
		);
	}

	public static function purge( int $space_id ): array {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 ) {
			return array();
		}

		self::$last_error = '';

		// Drains by repeatedly calling the SAME bounded step the batched path
		// uses, rather than carrying a second full-sweep implementation that
		// could drift from it. Callers who cannot afford a long request should
		// use queue(); this one runs to completion.
		// A batched purge that falls back to finishing here brings the batches it
		// already ran along, so their authors are recounted and the purged hook
		// reports the whole purge.
		$removed = array_filter( (array) get_transient( 'jetonomy_purge_tally_' . $space_id ) );
		$authors = array_filter( (array) get_transient( 'jetonomy_purge_authors_' . $space_id ) );
		do {
			$step = self::purge_step( $space_id );
			foreach ( $step['removed'] as $key => $n ) {
				$removed[ $key ] = ( $removed[ $key ] ?? 0 ) + $n;
			}
			$authors = array_merge( $authors, $step['authors'] );
		} while ( ! $step['done'] && empty( $step['error'] ) );

		self::recount_authors( $authors );

		if ( ! empty( $step['error'] ) ) {
			// Not purged: the space row is still there, so a later run resumes.
			self::$last_error = (string) $step['error'];
			return $removed;
		}

		delete_transient( 'jetonomy_purge_tally_' . $space_id );
		delete_transient( 'jetonomy_purge_authors_' . $space_id );

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
	 * Delete every row that hangs off a set of topics or replies.
	 *
	 * The one-object half of this class's map: Post::delete() and
	 * Reply::delete() call it so a hard delete of a single topic or reply
	 * cleans up exactly what a space purge would have cleaned up for it -
	 * votes, flags, notifications, activity, attachments, revisions, tags,
	 * bookmarks, read state, subscriptions, and whatever Pro adds through
	 * `jetonomy_space_relations` - from the same declaration, with no second
	 * list to drift.
	 *
	 * The object rows themselves are NOT touched, and for `post` neither are
	 * its replies: the caller owns those, because they carry counters and
	 * hooks this class knows nothing about.
	 *
	 * @param string $ref 'post' or 'reply'.
	 * @param int[]  $ids Object ids.
	 * @return int Rows removed.
	 */
	public static function delete_dependents( string $ref, array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! in_array( $ref, array( 'post', 'reply' ), true ) ) {
			return 0;
		}

		$replies = table( 'replies' );
		$n       = 0;
		try {
			foreach ( self::relations() as $r ) {
				if ( $ref !== $r['ref'] || $r['self'] || ( $replies === $r['table'] && 'post_id' === $r['column'] ) ) {
					continue;
				}
				$n += self::delete_where_in( $r, $ids );
			}
		} catch ( \RuntimeException $e ) {
			// Leftover dependents are swept with the orphan backfill; a failed
			// lookup must not break the delete the caller is in the middle of.
			return $n;
		}
		return $n;
	}

	/**
	 * Distinct authors of the rows about to be deleted.
	 *
	 * @param int[] $post_ids  Topic ids in this slice.
	 * @param int[] $reply_ids Reply ids in this slice.
	 * @return int[]
	 */
	private static function author_ids( array $post_ids, array $reply_ids ): array {
		global $wpdb;

		$out = array();

		foreach ( array( array( table( 'posts' ), $post_ids ), array( table( 'replies' ), $reply_ids ) ) as [ $tbl, $ids ] ) {
			if ( ! $ids || ! self::table_exists( $tbl ) ) {
				continue;
			}
			foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$out = array_merge( $out, (array) $wpdb->get_col( "SELECT DISTINCT author_id FROM {$tbl} WHERE id IN ({$in})" ) );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $out ) ) ) );
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
			return array();
		}

		// $limit > 0 is the batched path: take a slice instead of every id. A
		// 50k-topic space would otherwise materialise 50k ids just to start.
		$sql = $limit > 0
			? $wpdb->prepare( "SELECT id FROM {$posts} WHERE space_id = %d ORDER BY id ASC LIMIT %d", $space_id, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT id FROM {$posts} WHERE space_id = %d", $space_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::ids_or_fail( $sql );
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
			return array();
		}

		$out = array();
		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$tail = $limit > 0 ? ' ORDER BY id ASC LIMIT ' . (int) ( $limit - count( $out ) ) : '';
			$out  = array_merge( $out, self::ids_or_fail( "SELECT id FROM {$replies} WHERE post_id IN ({$in}){$tail}" ) );

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
	 * @throws \RuntimeException When a delete fails.
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
			// A refused delete must stop the purge, or the parent rows go and these become orphans.
			self::fail_on_db_error();
		}
		return $n;
	}

	/**
	 * Does a table exist? Pro tables are absent when the extension never ran.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 * @throws \RuntimeException When the lookup itself fails.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// Only a positive answer is remembered: a table never disappears
		// mid-request, but a Pro extension may create one. Without this every
		// single-topic delete paid one SHOW TABLES per relation.
		static $exists = array();
		if ( isset( $exists[ $table ] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$exists[ $table ] = true;
			return true;
		}
		// A failed lookup is not "no table": treating it so would skip a
		// whole table's rows and still report the purge done.
		self::fail_on_db_error();
		return false;
	}

	/**
	 * Run an id lookup, failing loudly on a database error.
	 *
	 * An empty answer means "nothing left to delete" to every caller, so a
	 * failed query must never look like one.
	 *
	 * @param string $sql Prepared SELECT returning one id column.
	 * @return int[]
	 * @throws \RuntimeException When the query fails.
	 */
	private static function ids_or_fail( string $sql ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $sql );
		self::fail_on_db_error();
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Raise if the last query failed.
	 *
	 * @throws \RuntimeException When it did.
	 */
	private static function fail_on_db_error(): void {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		if ( '' !== $error ) {
			throw new \RuntimeException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; caught by purge_step().
		}
	}
}
