<?php
/**
 * Post model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Cache;
use function Jetonomy\now;

/**
 * Represents a forum post (topic, question, idea, etc.).
 */
class Post extends Model {

	/** Columns the topic edit form writes; fingerprinted by edit_token(). */
	public const EDIT_FIELDS = array( 'title', 'content' );

	/**
	 * Get the table name suffix.
	 *
	 * @return string
	 */
	protected static function table_name(): string {
		return 'posts';
	}

	/**
	 * Create a new post.
	 *
	 * Sets created_at, updated_at, and status defaults if absent. Generates a
	 * slug from the title when one is not provided. After a successful insert,
	 * increments the parent space's post_count.
	 *
	 * @param array $data Column data.
	 * @return int Inserted row ID.
	 */
	/**
	 * Insert override: the LAST write barrier for the content pipeline.
	 *
	 * Sanitizing only inside create() left two bypasses (QA 2026-07-30):
	 * a jetonomy_before_create_post filter could replace content AFTER the
	 * early sanitize ran, and the inherited public Model::insert() skipped
	 * the pipeline entirely. Sanitizing here - immediately before the row
	 * is written - closes both: create() reaches insert AFTER its filter,
	 * and a direct Post::insert() call gets the same treatment.
	 *
	 * @param array $data Column data.
	 * @return int New row id.
	 */
	public static function insert( array $data ): int {
		return parent::insert( self::sanitize_content_fields( $data ) );
	}

	public static function create( array $data ): int|\WP_Error {
		/**
		 * Filter post data before creation. Return WP_Error to abort.
		 *
		 * @param array $data      Column data.
		 * @param int   $author_id Author user ID.
		 * @param int   $space_id  Space ID.
		 */
		$data = apply_filters( 'jetonomy_before_create_post', $data, $data['author_id'] ?? 0, $data['space_id'] ?? 0 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now  = now();
		$data = array_merge(
			array(
				'status'        => 'publish',
				'is_anonymous'  => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
				'last_reply_at' => $now,
			),
			$data
		);

		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = sanitize_title( $data['title'] );
		}

		/*
		 * Derive type from the holding space when the caller did not set one.
		 *
		 * Post type is behavioural, not cosmetic: Schema_Markup emits QAPage
		 * with acceptedAnswer only for `question`, so a Q&A post left as
		 * `topic` silently serves DiscussionForumPosting and loses the rich
		 * result (the same defect Migration_1_9_3 was written to repair).
		 *
		 * Until 1.9.4 the mapping lived only in callers - the REST controller,
		 * the composer template and the three importers each called
		 * compose_post_type() themselves. Anything else reached insert() with
		 * no type and took the column default, `topic`. Measured on one feed
		 * space: REST create produced `status` correctly, while Post::create()
		 * and the create-post ABILITY - the path an AI agent uses - both
		 * produced `topic`. That is why 1.9.3's migration did not hold: it
		 * repaired existing rows while those two paths kept minting new bad
		 * ones, 28 of them after the migration ran.
		 *
		 * Deriving it here makes the model the single source of truth. Callers
		 * that pass an explicit type still win, so REST, the importers and any
		 * deliberate override are unaffected.
		 */
		if ( empty( $data['type'] ) && ! empty( $data['space_id'] ) ) {
			$space = Space::find( (int) $data['space_id'] );
			if ( $space ) {
				$data['type'] = \Jetonomy\compose_post_type( (string) ( $space->type ?? 'forum' ) );
			}
		}

		$id = static::insert( $data );

		if ( $id > 0 ) {
			// Only increment counters for published posts (not drafts/scheduled).
			if ( 'publish' === ( $data['status'] ?? 'publish' ) ) {
				if ( ! empty( $data['space_id'] ) ) {
					Space::increment_post_count( (int) $data['space_id'] );
				}
				if ( ! empty( $data['author_id'] ) ) {
					UserProfile::increment_post_count( (int) $data['author_id'] );
				}

				// Auto-join the author to the space on their first post.
				// Only applies to open spaces — approval/invite-only spaces manage
				// membership through their own workflows, and demoting an existing
				// admin/moderator back to 'member' would be a footgun.
				self::maybe_auto_join_space( (int) ( $data['space_id'] ?? 0 ), (int) ( $data['author_id'] ?? 0 ) );

				/**
				 * Fires when a post enters or leaves `publish`.
				 *
				 * Fired here for posts created directly as publish;
				 * Post::update() fires it for later transitions
				 * (pending→publish approval, publish→trash, restore).
				 * Date-bucketed consumers (Pro analytics aggregate) key on
				 * $created_at so a deletion decrements the same calendar
				 * bucket the creation incremented — keeping the aggregate in
				 * lockstep with `WHERE status = 'publish'` query paths.
				 *
				 * @since 1.5.0
				 *
				 * @param int    $post_id    Post ID.
				 * @param int    $delta      +1 entering publish, -1 leaving it.
				 * @param string $created_at Post creation datetime (MySQL, UTC).
				 */
				do_action( 'jetonomy_post_publish_transition', (int) $id, 1, (string) $data['created_at'] );
			}

			/**
			 * Fires after a post is created.
			 *
			 * Lets gamification / analytics / external systems score the
			 * creation event itself rather than waiting on downstream votes.
			 * Fires for every status (publish, draft, scheduled) — listeners
			 * should inspect $context['status'] when status matters.
			 *
			 * @param int   $post_id  Inserted post ID.
			 * @param int   $space_id Parent space ID (0 if unset).
			 * @param int   $user_id  Author user ID (0 if unset).
			 * @param array $context  Inserted column data (status, post_type,
			 *                        idea_status, slug, etc.) for the listener
			 *                        to disambiguate by.
			 */
			do_action(
				'jetonomy_post_created',
				(int) $id,
				(int) ( $data['space_id'] ?? 0 ),
				(int) ( $data['author_id'] ?? 0 ),
				$data
			);
		}

		if ( $id && 'draft' === ( $data['status'] ?? '' ) && ! empty( $data['published_at'] ) ) {
			\Jetonomy\Cron::arm_scheduled_publish();
		}

		return $id;
	}

	/**
	 * Status-aware update.
	 *
	 * When a post transitions between `publish` and any other status the
	 * denormalized counters on the parent space and the author profile must
	 * track that transition. Centralizing it here means every caller — REST,
	 * admin AJAX, abilities, WP-CLI, migrations — stays consistent without
	 * repeating increment/decrement logic.
	 *
	 * Transitions that update counters:
	 *   publish → non-publish     : -1 space.post_count, -1 user.post_count
	 *   non-publish → publish     : +1 space.post_count, +1 user.post_count
	 *   everything else           : no-op
	 */
	/**
	 * Single-post read, served from the object cache.
	 *
	 * The hottest read in the product: every thread view starts here, and it
	 * went straight to MySQL on every request while the profile row rendered
	 * beside it came from Redis.
	 *
	 * remember_object(), NOT remember(). A miss caches null, and Redis
	 * materialises a stored null as an empty string on the way back out -
	 * remember() would treat that as a hit and hand '' to a caller whose
	 * signature says ?object, which is a TypeError at the call site rather
	 * than here. remember_object() coerces every non-object hit back to null
	 * so the contract holds. The caching gate exercises this deliberately by
	 * requesting a deleted post twice.
	 *
	 * TTL is short by design. Post rows carry counters (reply_count,
	 * vote_score, last_reply_at) that other paths bump without going through
	 * update(), so a long TTL would show stale numbers no invalidation hook
	 * knows to clear.
	 *
	 * @param int $id Post ID.
	 */
	public static function find( int $id ): ?object {
		if ( $id <= 0 ) {
			return null;
		}

		return Cache::remember_object(
			self::cache_key( $id ),
			static fn () => parent::find( $id ),
			300
		);
	}

	/**
	 * Cache key for one post row.
	 *
	 * @param int $id Post ID.
	 */
	private static function cache_key( int $id ): string {
		return 'post:' . $id;
	}

	/**
	 * Drop every cached read that this post appears in.
	 *
	 * Called from insert/update/delete so a write is visible on the very next
	 * read rather than at TTL expiry - the difference between "the cache is
	 * working" and "the site is lying to people".
	 *
	 * @param int $id Post ID.
	 */
	public static function bust_cache( int $id ): void {
		Cache::delete( self::cache_key( $id ) );
	}

	public static function update( int $id, array $data ): bool {
		$data = self::sanitize_content_fields( $data );

		$delta       = 0;
		$post        = null;
		$was_publish = false;
		$is_publish  = false;

		if ( array_key_exists( 'status', $data ) || array_key_exists( 'space_id', $data ) ) {
			$post = self::find( $id );
			if ( $post ) {
				$was_publish = 'publish' === ( $post->status ?? '' );
				$is_publish  = array_key_exists( 'status', $data ) ? 'publish' === (string) $data['status'] : $was_publish;
				if ( $was_publish && ! $is_publish ) {
					$delta = -1;
				} elseif ( ! $was_publish && $is_publish ) {
					$delta = 1;
				}
			}
		}

		$result = parent::update( $id, $data );
		self::reset_slug_memo();

		/*
		 * AFTER the write, never before.
		 *
		 * Busting first looks safer and is not: this method calls self::find()
		 * a few lines up to read the pre-write status, which RE-CACHES the old
		 * row after the bust and leaves the stale value sitting there for the
		 * whole TTL. Same re-prime race UserProfile::update_profile() already
		 * documents, arrived at the same way.
		 */
		self::bust_cache( $id );

		// A space counts its published topics: the old space loses one it had,
		// the new one gains one it now has. Covers a publish change, a move to
		// another space, and both at once, for every caller.
		if ( $post ) {
			$old_space = (int) ( $post->space_id ?? 0 );
			$new_space = array_key_exists( 'space_id', $data ) ? (int) $data['space_id'] : $old_space;
			if ( $old_space !== $new_space || $was_publish !== $is_publish ) {
				if ( $was_publish && $old_space > 0 ) {
					Space::increment_post_count( $old_space, -1 );
				}
				if ( $is_publish && $new_space > 0 ) {
					Space::increment_post_count( $new_space, 1 );
				}
			}
		}

		if ( 0 !== $delta && $post ) {
			if ( ! empty( $post->author_id ) ) {
				UserProfile::increment_post_count( (int) $post->author_id, $delta );
			}
			self::shift_replier_counts( $id, $delta );

			/** This action is documented in includes/models/class-post.php (Post::create) */
			do_action( 'jetonomy_post_publish_transition', $id, $delta, (string) ( $data['created_at'] ?? $post->created_at ?? '' ) );
		}

		// published_at on a draft is its schedule: every write that sets or
		// clears it moves the next publish time.
		if ( array_key_exists( 'published_at', $data ) ) {
			\Jetonomy\Cron::arm_scheduled_publish();
		}

		return $result;
	}

	/**
	 * Add the author to a space as a plain member if they aren't already a member.
	 * Skips non-open spaces so role/gating workflows aren't bypassed.
	 */
	private static function maybe_auto_join_space( int $space_id, int $user_id ): void {
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return;
		}
		if ( SpaceMember::is_member( $space_id, $user_id ) ) {
			return;
		}
		$space = Space::find( $space_id );
		if ( ! $space || 'open' !== ( $space->join_policy ?? 'open' ) ) {
			return;
		}
		SpaceMember::add( $space_id, $user_id, 'member' );
	}

	/**
	 * Delete a post by ID.
	 *
	 * Fires `jetonomy_before_delete_post` before deletion. Return WP_Error
	 * from the filter to abort.
	 *
	 * @param int $id Post ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( int $id ): bool|\WP_Error {
		/**
		 * Filter whether a post deletion should proceed. Return WP_Error to abort.
		 *
		 * @param bool $proceed Whether to proceed with deletion.
		 * @param int  $id      Post ID.
		 */
		$proceed = apply_filters( 'jetonomy_before_delete_post', true, $id );
		if ( is_wp_error( $proceed ) ) {
			return $proceed;
		}

		// Load before deletion so a hard delete reverses the same counters a
		// published post incremented in create(). REST deletes go through
		// update( status => trash ), which already handles this; the direct
		// delete path (CLI content journey, QA fixtures, abilities) must mirror
		// it so space + author post_count stay consistent across every delete
		// mechanism. Mirrors Reply::delete().
		$post = self::find( $id );

		// Replies first, while the topic row still exists for any listener
		// that looks it up. Deleting the replies with the topic keeps them from pointing at
		// a missing post_id - still counted on their authors, still in search and activity.
		if ( $post ) {
			self::delete_replies( $id, 'publish' === ( $post->status ?? '' ) );
		}

		$result = parent::delete( $id );
		self::reset_slug_memo();

		// After the delete: the self::find() above re-primes the cache with
		// the row that is about to disappear, so busting any earlier leaves a
		// deleted post readable for the rest of its TTL.
		self::bust_cache( $id );

		if ( true === $result && $post && 'publish' === ( $post->status ?? '' ) ) {
			if ( ! empty( $post->space_id ) ) {
				Space::increment_post_count( (int) $post->space_id, -1 );
			}
			if ( ! empty( $post->author_id ) ) {
				UserProfile::increment_post_count( (int) $post->author_id, -1 );
			}

			/** This action is documented in includes/models/class-post.php (Post::create) */
			do_action( 'jetonomy_post_publish_transition', $id, -1, (string) ( $post->created_at ?? '' ) );
		}

		if ( true === $result ) {
			// Through the model so each tag's post_count drops; a raw delete of
			// post_tags would leave the tag cloud counting a topic that is gone.
			foreach ( Tag::list_for_post( $id ) as $tag ) {
				Tag::detach_from_post( $id, (int) $tag->id );
			}

			// Everything else pointing at the topic - votes, flags, bookmarks,
			// read state, subscriptions, notifications, activity, revisions,
			// attachment links and Pro's tables - from the same relation map a
			// space purge uses, so the two can never disagree about what a
			// topic owns.
			\Jetonomy\Space_Purge::delete_dependents( 'post', array( $id ) );

			/**
			 * Fires after a post row is deleted, whatever deleted it.
			 *
			 * The hook fires from the model, where every caller converges (REST, `wp jetonomy
			 * content delete`, the QA/journey harness, any internal Post::delete()), so Pro's
			 * wp_jt_attachments rows never point at deleted posts. Attachment GC cannot catch
			 * such orphans: it checks whether the media FILE still exists, not the post.
			 *
			 * @since 1.4.1
			 * @param int $id Deleted post ID.
			 */
			do_action( 'jetonomy_after_delete_post', $id );

			// A deleted scheduled draft may have been the one the publisher
			// was armed for.
			if ( $post && 'draft' === ( $post->status ?? '' ) && ! empty( $post->published_at ) ) {
				\Jetonomy\Cron::arm_scheduled_publish();
			}
		}

		return $result;
	}

	/**
	 * Move the repliers' reply_count with their topic when it enters or leaves publish.
	 *
	 * A member's reply_count counts published replies in published topics (Recount
	 * defines it so). Trashing a topic hides its replies with it, so they stop
	 * counting and come back on restore. One grouped read, one UPDATE per replier.
	 *
	 * @param int $post_id Topic.
	 * @param int $delta   +1 entering publish, -1 leaving it.
	 */
	private static function shift_replier_counts( int $post_id, int $delta ): void {
		$replies = \Jetonomy\table( 'replies' );
		$rows    = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT author_id, COUNT(*) AS n FROM {$replies} WHERE post_id = %d AND status = 'publish' AND author_id > 0 GROUP BY author_id",
				$post_id
			)
		) ?: array();
		foreach ( $rows as $row ) {
			UserProfile::increment_reply_count( (int) $row->author_id, $delta * (int) $row->n );
		}
	}

	/** Replies removed per pass when a topic is hard-deleted. */
	private const REPLY_DELETE_BATCH = 500;

	/**
	 * Hard-delete every reply of a topic that is itself being hard-deleted.
	 *
	 * Batched, NOT a loop over Reply::delete(). A 5,000-reply topic through
	 * Reply::delete() is ~40,000 queries: a find, the row delete, an update
	 * of the dying topic's reply_count, an author counter update and a thread
	 * cache bump per reply, plus the dependents sweep per reply. Here each
	 * pass of 500 costs one SELECT, one DELETE per dependent table, one row
	 * DELETE and one counter UPDATE per distinct author.
	 *
	 * What Reply::delete() guarantees is kept, because other code relies on
	 * it: author reply_count drops for every published reply, and the two
	 * per-reply actions still fire for each row - `jetonomy_reply_publish_transition`
	 * (Pro analytics) and `jetonomy_after_delete_reply` (Pro attachment links,
	 * BuddyNext's mirrored feed comments). They fire after the row is gone,
	 * exactly as Reply::delete() fires them.
	 *
	 * Deliberately skipped: the `jetonomy_before_delete_reply` veto. The
	 * topic's own `jetonomy_before_delete_post` already decided; a reply
	 * cannot outlive the topic it belongs to. And the topic's reply_count is
	 * not maintained - the row it lives on is deleted next.
	 *
	 * @param int  $post_id Topic being deleted.
	 * @param bool $counted Whether the topic is published, so its replies still count on their authors.
	 */
	private static function delete_replies( int $post_id, bool $counted ): void {
		$db      = static::db();
		$replies = \Jetonomy\table( 'replies' );

		do {
			$rows = $db->get_results(
				$db->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, author_id, status, created_at FROM {$replies} WHERE post_id = %d ORDER BY id ASC LIMIT %d",
					$post_id,
					self::REPLY_DELETE_BATCH
				)
			) ?: array();
			if ( ! $rows ) {
				break;
			}

			$ids   = array_map( static fn( $r ) => (int) $r->id, $rows );
			$batch = count( $ids );
			\Jetonomy\Space_Purge::delete_dependents( 'reply', $ids );

			$in = implode( ',', $ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ids are intval'd above.
			$db->query( "DELETE FROM {$replies} WHERE id IN ({$in})" );

			$published = array();
			foreach ( $rows as $r ) {
				if ( $counted && 'publish' === $r->status && (int) $r->author_id > 0 ) {
					$published[ (int) $r->author_id ] = ( $published[ (int) $r->author_id ] ?? 0 ) + 1;
				}
			}
			foreach ( $published as $author_id => $n ) {
				UserProfile::increment_reply_count( $author_id, -$n );
			}

			foreach ( $rows as $r ) {
				if ( 'publish' === $r->status ) {
					/** This action is documented in includes/models/class-reply.php (Reply::create) */
					do_action( 'jetonomy_reply_publish_transition', (int) $r->id, -1, (string) $r->created_at );
				}
				/** This action is documented in includes/models/class-reply.php (Reply::delete) */
				do_action( 'jetonomy_after_delete_reply', (int) $r->id );
			}
		} while ( self::REPLY_DELETE_BATCH === $batch );

		Reply::bust_thread( $post_id );
	}

	/**
	 * Mark + scrub a post row authored by a user the viewer has blocked.
	 *
	 * The post-shaped call into the one tombstone —
	 * {@see BlockedUser::apply_tombstone()}, the same treatment
	 * {@see Reply::apply_block_tombstone()} gives a reply. `title` is emptied
	 * alongside the body because a post title is authored text too: leaving it
	 * would ship the blocked user's words under a flag that says they were
	 * hidden.
	 *
	 * Needed on the by-id / by-slug paths (GET /posts/{id}, single-post.php).
	 * Every list surface already excludes blocked authors in SQL, but a deep
	 * link — a notification, a share, a search result that predates the block —
	 * reaches the row directly and bypasses that filter entirely.
	 *
	 * @since 1.8.0
	 * @param object $post        Post row, mutated in place.
	 * @param int[]  $blocked_ids Viewer's blocked author ids.
	 */
	public static function apply_block_tombstone( object $post, array $blocked_ids ): void {
		BlockedUser::apply_tombstone( $post, $blocked_ids, array( 'title', 'content', 'content_plain' ) );
	}

	/**
	 * Per-request memo for find_by_slug(), POSITIVE hits only.
	 *
	 * A single-post view resolves the same slug 6-7x (template loader x4,
	 * the view, schema markup x2). Misses are deliberately never memoized:
	 * unique_post_slug() loops `while ( find_by_slug() )` as its duplicate
	 * guard, and a cached miss there mints duplicate slugs — the exact bug
	 * Space's negative slug cache had.
	 * Reset on every post write (update/delete) and via Cache::flush() (U1).
	 *
	 * @var array<string, object>
	 */
	private static array $slug_memo = array();

	/**
	 * Whether the memo reset is registered with Cache::flush().
	 *
	 * @var bool
	 */
	private static bool $slug_memo_registered = false;

	/**
	 * Empty the slug memo. Called from update()/delete() — a mid-request
	 * status change or slug rename must be visible to the next lookup.
	 */
	public static function reset_slug_memo(): void {
		self::$slug_memo = array();
	}

	/**
	 * Find a post by its slug.
	 *
	 * @param string $slug Post slug.
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ): ?object {
		if ( ! self::$slug_memo_registered ) {
			self::$slug_memo_registered = true;
			\Jetonomy\Cache::register_memo_reset(
				static function (): void {
					self::$slug_memo = array();
				}
			);
		}

		if ( isset( self::$slug_memo[ $slug ] ) ) {
			// Clone so caller-side mutation (block/private tombstoning blanks
			// content in place) never leaks into the next call site's copy.
			return clone self::$slug_memo[ $slug ];
		}

		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s',
				$slug
			)
		);

		if ( $row ) {
			self::$slug_memo[ $slug ] = clone $row;
			return $row;
		}

		return null;
	}

	/**
	 * List published posts in a space with sorting and cursor-based pagination.
	 *
	 * Sort options:
	 *   'latest'      — sticky posts first, then last_reply_at DESC
	 *   'popular'     — votes, then replies (popular_order())
	 *   'oldest'      — created_at ASC
	 *   'newest'      — created_at DESC
	 *   'unanswered'  — reply_count = 0, created_at DESC
	 *
	 * Cursor param:
	 *   $after > 0  — return only rows with id > $after (forward cursor)
	 *
	 * @param int    $space_id Space ID.
	 * @param string $sort     Sort order.
	 * @param int    $limit    Max rows.
	 * @param int    $offset   Legacy offset; ignored when $after > 0.
	 * @param int    $after   Cursor: return items after this post ID.
	 * @return object[]
	 */
	public static function list_by_space( int $space_id, string $sort = 'latest', int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			$limit = Space::get_posts_per_page( $space_id );
		}
		$table = static::table();

		$extra_where = '';

		// Every ORDER BY ends in `id` as a tiebreaker. The leading keys are all
		// non-unique — 25 of 26 seeded posts shared a single created_at second —
		// and without a unique final key MySQL may return tied rows in any order,
		// so consecutive pages of the same list could repeat or skip rows
		// Tiebreaker direction matches its key's.
		switch ( $sort ) {
			case 'popular':
				$order_by = 'is_sticky DESC, ' . self::popular_order();
				break;

			case 'oldest':
				$order_by = 'is_sticky DESC, created_at ASC, id ASC';
				break;

			case 'newest':
				$order_by = 'is_sticky DESC, created_at DESC, id DESC';
				break;

			case 'unanswered':
				$order_by = 'is_sticky DESC, created_at DESC, id DESC';
				// On Q&A spaces "unanswered" means "no accepted answer yet";
				// elsewhere it means "no replies yet". The same pill label
				// covers both because each space type's contract makes the
				// definition unambiguous to its members.
				$_jt_space   = Space::find( $space_id );
				$extra_where = ( $_jt_space && 'qa' === ( $_jt_space->type ?? '' ) )
					? ' AND accepted_reply_id IS NULL'
					: ' AND reply_count = 0';
				break;

			case 'latest':
			default:
				$order_by = 'is_sticky DESC, last_reply_at DESC, id DESC';
				break;
		}

		/**
		 * Filter post query parameters before execution.
		 *
		 * @param array $args     Query parameters: extra_where, order_by, limit, offset, after.
		 * @param int   $space_id Space ID being queried.
		 */
		$args = apply_filters(
			'jetonomy_posts_query_args',
			array(
				'extra_where' => $extra_where,
				'order_by'    => $order_by,
				'limit'       => $limit,
				'offset'      => $offset,
				'after'       => $after,
			),
			$space_id
		);

		$extra_where = $args['extra_where'];
		$order_by    = $args['order_by'];
		$limit       = (int) $args['limit'];
		$offset      = (int) $args['offset'];
		$after       = (int) $args['after'];

		// $after is a forward cursor carrying an OFFSET, not a row id.
		//
		// Offset is the only scheme these sorts support: $order_by leads with is_sticky/
		// vote_score/last_reply_at/created_at, so a keyset `id > %d` filter does not follow
		// the sort and would return the top of the list for every "next page".
		$offset = $after > 0 ? $after : $offset;

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$space_id,
				$limit,
				$offset
			)
		);
		return $results ? $results : array();
	}

	/**
	 * List published posts in a space, filtering private posts by visibility.
	 *
	 * Moderators and admins see all posts. Regular members only see public
	 * posts plus their own private posts.
	 *
	 * @param int    $space_id       Space ID.
	 * @param int    $user_id        Current viewer's user ID (0 for guest).
	 * @param bool   $is_privileged  True if viewer is moderator/admin/WP-admin.
	 * @param string $sort           Sort order.
	 * @param int    $limit          Max rows.
	 * @param int    $offset         Legacy offset; ignored when $after > 0.
	 * @param int    $after          Cursor: return items after this post ID.
	 * @return object[]
	 */
	public static function list_by_space_visible( int $space_id, int $user_id, bool $is_privileged, string $sort = 'latest', int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			$limit = Space::get_posts_per_page( $space_id );
		}
		$table = static::table();

		$extra_where = '';

		// Every ORDER BY ends in `id` as a tiebreaker. The leading keys are all
		// non-unique — 25 of 26 seeded posts shared a single created_at second —
		// and without a unique final key MySQL may return tied rows in any order,
		// so consecutive pages of the same list could repeat or skip rows
		// Tiebreaker direction matches its key's.
		switch ( $sort ) {
			case 'popular':
				$order_by = 'is_sticky DESC, ' . self::popular_order();
				break;

			case 'oldest':
				$order_by = 'is_sticky DESC, created_at ASC, id ASC';
				break;

			case 'newest':
				$order_by = 'is_sticky DESC, created_at DESC, id DESC';
				break;

			case 'unanswered':
				$order_by = 'is_sticky DESC, created_at DESC, id DESC';
				// On Q&A spaces "unanswered" means "no accepted answer yet";
				// elsewhere it means "no replies yet". The same pill label
				// covers both because each space type's contract makes the
				// definition unambiguous to its members.
				$_jt_space   = Space::find( $space_id );
				$extra_where = ( $_jt_space && 'qa' === ( $_jt_space->type ?? '' ) )
					? ' AND accepted_reply_id IS NULL'
					: ' AND reply_count = 0';
				break;

			case 'latest':
			default:
				$order_by = 'is_sticky DESC, last_reply_at DESC, id DESC';
				break;
		}

		$extra_where .= self::space_reader_sql( $user_id, $is_privileged );

		// $after carries an OFFSET, not a row id — see list_by_space() for why the
		// old `id > %d` keyset could not work against these sorts.
		$offset = $after > 0 ? $after : $offset;

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$space_id,
				$limit,
				$offset
			)
		);
		$results = $results ? $results : array();

		/**
		 * Filter the result set returned for a space's post list.
		 *
		 * Lets Pro extensions (site-announcements / super-sticky) inject
		 * cross-space pinned posts at the top of every space's view without
		 * each space template needing to know about them.
		 *
		 * @param object[] $results       Database rows in display order.
		 * @param int      $space_id      Space ID being viewed.
		 * @param int      $user_id       Current viewer's user ID (0 for guest).
		 * @param bool     $is_privileged True if viewer is moderator/admin.
		 * @param string   $sort          Current sort order (latest|popular|oldest|newest|unanswered).
		 * @param int      $offset        Pagination offset (0 = first page).
		 */
		return apply_filters( 'jetonomy_post_list_results_for_space', $results, $space_id, $user_id, $is_privileged, $sort, $offset );
	}

	/**
	 * Card-projection variant of list_by_space_visible().
	 *
	 * The topic-card templates render title + a trimmed excerpt — never the
	 * body — yet the full method dragged `content` LONGTEXT for every row.
	 * This drops `content` and truncates `content_plain` to the excerpt
	 * window. Used ONLY by post-card template paths; the original stays
	 * SELECT * because four consumers genuinely need the full columns
	 * (feed-card renders the whole body, RSS descriptions, the REST list
	 * payload's content/content_plain contract, BuddyPress) — trimming
	 * them was the safety review's confirmed break. Rows injected by the
	 * jetonomy_post_list_results_for_space filter (Pro announcements) are
	 * `p.*` and merge fine — the card templates read a SUBSET of this
	 * projection, so heterogeneous rows only over-carry, never break.
	 *
	 * Same signature, ordering, gating and filter as the original.
	 *
	 * @param int    $space_id      Space.
	 * @param int    $user_id       Viewer (0 guest).
	 * @param bool   $is_privileged Whether viewer sees private content.
	 * @param string $sort          Sort flag.
	 * @param int    $limit         Page size (-1 = space setting).
	 * @param int    $offset        Offset.
	 * @param int    $after         Legacy offset alias.
	 * @return object[]
	 */
	public static function list_cards_by_space_visible( int $space_id, int $user_id, bool $is_privileged, string $sort = 'latest', int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			$limit = Space::get_posts_per_page( $space_id );
		}
		$table = static::table();

		$extra_where = '';
		switch ( $sort ) {
			case 'popular':
				$order_by = 'is_sticky DESC, ' . self::popular_order();
				break;
			case 'oldest':
				$order_by = 'is_sticky DESC, created_at ASC, id ASC';
				break;
			case 'newest':
				$order_by = 'is_sticky DESC, created_at DESC, id DESC';
				break;
			case 'unanswered':
				$order_by    = 'is_sticky DESC, created_at DESC, id DESC';
				$_jt_space   = Space::find( $space_id );
				$extra_where = ( $_jt_space && 'qa' === ( $_jt_space->type ?? '' ) )
					? ' AND accepted_reply_id IS NULL'
					: ' AND reply_count = 0';
				break;
			case 'latest':
			default:
				$order_by = 'is_sticky DESC, last_reply_at DESC, id DESC';
				break;
		}

		$extra_where .= self::space_reader_sql( $user_id, $is_privileged );

		$offset = $after > 0 ? $after : $offset;

		// Every column except the two longtexts; content_plain truncated to
		// the excerpt window (jetonomy_post_title_or_excerpt trims to words
		// well inside 300 chars). `content` is intentionally ABSENT — a card
		// consumer reading it is a bug, and the helpers all isset()-guard.
		$card_columns = 'id, space_id, author_id, is_anonymous, type, prefix, title, slug, status,
			published_at, is_sticky, is_private, is_closed, is_resolved, idea_status,
			vote_score, reply_count, view_count, flag_count, last_reply_at, last_reply_by,
			accepted_reply_id, edited_at, edited_by, updated_at, created_at,
			LEFT(content_plain, 300) AS content_plain';

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT {$card_columns} FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$space_id,
				$limit,
				$offset
			)
		);
		$results = $results ? $results : array();

		/** This filter is documented in includes/models/class-post.php (list_by_space_visible). */
		return apply_filters( 'jetonomy_post_list_results_for_space', $results, $space_id, $user_id, $is_privileged, $sort, $offset );
	}

	/**
	 * Count visible posts in a space — viewer-aware companion to
	 * list_by_space_visible(). Used by abilities/REST listings to report
	 * accurate totals for cursor-based pagination instead of guessing
	 * "has_more" from the page-size hit.
	 *
	 * @param int    $space_id      Space.
	 * @param int    $user_id       Current viewer (0 for guest).
	 * @param bool   $is_privileged Whether viewer sees private content.
	 * @param string $sort         Same sort flag as list — only matters because
	 *                             'unanswered' applies an extra WHERE clause.
	 * @return int
	 */
	public static function count_by_space_visible( int $space_id, int $user_id, bool $is_privileged, string $sort = 'latest' ): int {
		// Cached 120s per viewer bucket: the COUNT doubles the
		// query cost of the hottest list on the site for a pager number that
		// barely moves. Bucket honors EVERY viewer-dependent clause below
		// (U2): privileged viewers share 'priv' (they see everything),
		// guests share 'guest' (no blocks by definition), and any logged-in
		// non-privileged viewer gets a per-user key — the own-private clause
		// binds their literal id. TTL-ONLY by design: the sort+bucket key
		// set is unbounded per space, so no write can enumerate it, and a
		// ≤120s-stale pager count is invisible. The denormalized
		// jt_spaces.post_count is a DIFFERENT population and is never
		// substituted here (it would give wrong pager counts).
		$bucket = $is_privileged ? 'priv' : ( $user_id > 0 ? "u{$user_id}" : 'guest' );
		$key    = "postcount:{$space_id}:{$sort}:{$bucket}";
		$cached = Cache::get( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table       = static::table();
		$extra_where = '';

		if ( 'unanswered' === $sort ) {
			$_jt_space   = Space::find( $space_id );
			$extra_where = ( $_jt_space && 'qa' === ( $_jt_space->type ?? '' ) )
				? ' AND accepted_reply_id IS NULL'
				: ' AND reply_count = 0';
		}

		// The same filter as list_by_space_visible(), or the pager disagrees with the list.
		$extra_where .= self::space_reader_sql( $user_id, $is_privileged );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where}",
				$space_id
			)
		);

		Cache::set( $key, $count, 120 );
		return $count;
	}

	/**
	 * List posts in the given moderation statuses, newest first, paginated.
	 *
	 * Shared by the REST moderation queue and the wp-admin Moderation screen so
	 * both read one implementation instead of duplicating raw SQL. Served by the
	 * status_created (status, created_at) index.
	 *
	 * @param string[]                            $statuses  One or more of publish|pending|draft|spam|trash.
	 * @param int                                 $limit     Max rows.
	 * @param int                                 $offset    Pagination offset.
	 * @param int[]|array{skip_spaces:int[]}|null $space_ids Limit to these spaces, or null for every space.
	 * @return object[]
	 */
	public static function list_by_status( array $statuses, int $limit = 20, int $offset = 0, ?array $space_ids = null ): array {
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return array();
		}
		$scope = static::space_scope_sql( $space_ids );
		if ( null === $scope ) {
			return array();
		}
		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( $statuses, $scope['params'], array( $limit, $offset ) );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table trusted, $placeholders and $scope['sql'] are lists of %s / %d.
				"SELECT * FROM {$table} WHERE status IN ({$placeholders}){$scope['sql']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				...$params
			)
		) ?: array();
	}

	/**
	 * Build the optional space filter shared by the two status queries.
	 *
	 * The null-vs-empty distinction is the whole point and is easy to get
	 * wrong: null means "no filter", while an EMPTY array means "the caller
	 * has no spaces in scope" and must return nothing. Collapsing the two
	 * would hand a moderator who moderates no space the entire site's
	 * pending queue - which is the exact privilege boundary the frontend
	 * queue relies on.
	 *
	 * @param int[]|array{skip_spaces:int[]}|null $space_ids
	 * @return array{sql:string,params:array<int,int>}|null Null = match nothing.
	 */
	protected static function space_scope_sql( ?array $space_ids ): ?array {
		if ( null === $space_ids ) {
			return array(
				'sql'    => '',
				'params' => array(),
			);
		}
		// array( 'skip_spaces' => ids ): every space but these (a site moderator's
		// reach, Moderation_Permissions::staff_scope()). An empty exclude list
		// is no filter at all, unlike an empty include list.
		if ( isset( $space_ids['skip_spaces'] ) ) {
			$skip = array_values( array_unique( array_filter( array_map( 'absint', (array) $space_ids['skip_spaces'] ) ) ) );
			return array(
				'sql'    => $skip ? ' AND space_id NOT IN (' . implode( ', ', array_fill( 0, count( $skip ), '%d' ) ) . ')' : '',
				'params' => $skip,
			);
		}
		// Any other keyed array is a malformed scope: match nothing rather than
		// coerce it into space ids (fail closed - a moderator filter must never
		// silently widen).
		if ( array_filter( array_keys( $space_ids ), 'is_string' ) ) {
			return null;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $space_ids ) ) ) );
		if ( empty( $ids ) ) {
			return null;
		}

		return array(
			'sql'    => ' AND space_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			'params' => $ids,
		);
	}

	/**
	 * Count posts in the given moderation statuses via COUNT(*) (no row load).
	 *
	 * @param string[]                            $statuses
	 * @param int[]|array{skip_spaces:int[]}|null $space_ids Limit to these spaces, or null for every space.
	 * @return int
	 */
	public static function count_by_status( array $statuses, ?array $space_ids = null ): int {
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return 0;
		}
		$scope = static::space_scope_sql( $space_ids );
		if ( null === $scope ) {
			return 0;
		}
		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table trusted, $placeholders and $scope['sql'] are lists of %s / %d.
				"SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders}){$scope['sql']}",
				...array_merge( $statuses, $scope['params'] )
			)
		);
	}

	/**
	 * Who wrote each row, and whether anonymously - just those columns, in
	 * chunks, for screens that only need to mask an anonymous author (the
	 * Activity Log export can hold tens of thousands of ids).
	 *
	 * @param int[] $ids Row IDs.
	 * @return array<int, object> id => object with id, author_id, is_anonymous.
	 */
	public static function authorship_by_ids( array $ids ): array {
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		$by_id = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows         = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table trusted, $placeholders is a list of %d.
					'SELECT id, author_id, is_anonymous FROM ' . static::table() . " WHERE id IN ({$placeholders})",
					...$chunk
				)
			) ?: array();
			foreach ( $rows as $row ) {
				$by_id[ (int) $row->id ] = $row;
			}
		}
		return $by_id;
	}

	/**
	 * Hydrate post rows for a given set of IDs, in the order asked for.
	 *
	 * Mirrors Space::list_by_ids(). Exists so callers that start from
	 * something OTHER than posts - the moderation queue starts from replies
	 * and needs each reply's parent for its title and permalink - can batch
	 * one indexed query instead of a find() per row.
	 *
	 * @param int[] $ids
	 * @return array<int,object> Keyed by post id. Sparse: missing ids are absent.
	 */
	public static function list_by_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table trusted, $placeholders is a list of %d.
				'SELECT * FROM ' . static::table() . " WHERE id IN ({$placeholders})",
				...$ids
			)
		) ?: array();

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}

		return $by_id;
	}

	/**
	 * Adjust reply_count and update last_reply_at and updated_at.
	 *
	 * Pass a negative value to decrement. Uses GREATEST() to prevent
	 * the counter from going below zero.
	 *
	 * @param int         $id Post ID.
	 * @param int         $by Amount to adjust (default +1).
	 * @param string|null $at Optional UTC datetime to record as last_reply_at
	 *                        (importer seam: a backdated reply carries its own
	 *                        date, not the migration run time). Null = now.
	 */
	public static function increment_reply_count( int $id, int $by = 1, ?string $at = null ): void {
		$now = now();

		// A REMOVAL must not advertise new activity. This wrote last_reply_at =
		// now() for every call, so trashing or spamming a reply bumped its
		// thread to the top of "recent activity" - moderating a spam reply
		// promoted the thread it was spamming. Only a positive delta is new
		// activity; a decrement leaves the timestamp where it was.
		if ( $by > 0 ) {
			static::db()->query(
				static::db()->prepare(
					'UPDATE ' . static::table() . ' SET reply_count = GREATEST(reply_count + %d, 0), last_reply_at = %s, updated_at = %s WHERE id = %d',
					$by,
					( null !== $at && '' !== $at ) ? $at : $now,
					$now,
					$id
				)
			);
		} else {
			static::db()->query(
				static::db()->prepare(
					'UPDATE ' . static::table() . ' SET reply_count = GREATEST(reply_count + %d, 0), updated_at = %s WHERE id = %d',
					$by,
					$now,
					$id
				)
			);
		}

		// reply_count and last_reply_at both live on the cached row.
		self::bust_cache( $id );
	}

	/**
	 * Adjust the denormalised pending-flag counter (clamped at 0). Deliberately
	 * does NOT touch updated_at — a report isn't a content edit and shouldn't
	 * bump the post's modified time. Maintained by Flag::create()/resolve().
	 *
	 * @param int $id Post ID.
	 * @param int $by Delta (default +1).
	 */
	public static function increment_flag_count( int $id, int $by = 1 ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET flag_count = GREATEST(flag_count + %d, 0) WHERE id = %d',
				$by,
				$id
			)
		);

		// flag_count is read straight off the cached row by the moderation UI.
		self::bust_cache( $id );
	}

	/**
	 * Increment view_count by 1.
	 *
	 * @param int $id Post ID.
	 */
	public static function increment_view_count( int $id ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET view_count = view_count + 1 WHERE id = %d',
				$id
			)
		);

		/*
		 * Deliberately does NOT bust the cache.
		 *
		 * This fires on every single page view, so busting here would clear the
		 * post row on every read and Post::find() would never serve a warm hit -
		 * the cache would exist and do nothing, which is worse than not having
		 * it, because it looks like coverage.
		 *
		 * The cost is that view_count can trail by up to the row's TTL. A view
		 * counter is approximate by nature - it is already racy under
		 * concurrency and nobody reconciles it - so a few minutes of lag is
		 * within what the number already means. Every other column on this row
		 * has a real invalidation path.
		 */
	}

	/**
	 * List published posts by a specific author.
	 *
	 * Anonymous posts (is_anonymous = 1) are always excluded, including from
	 * the author's own profile: surfacing them here, even to the author
	 * themselves, would deanonymize the post by correlating it to this
	 * identity, defeating the anonymity the space/activity views already mask.
	 *
	 * @param int $user_id Author user ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Pagination offset.
	 * @return object[] Post rows with space_slug and space_title joined.
	 */
	public static function list_by_author( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$table      = static::table();
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		[ $gate_sql, $gate_params ] = self::viewer_gate_sql( 's' );

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.*, s.slug AS space_slug, s.title AS space_title FROM {$table} p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$gate_sql}
				 ORDER BY p.created_at DESC, p.id DESC LIMIT %d OFFSET %d",
				$user_id,
				...array_merge( $gate_params, array( $limit, $offset ) )
			)
		);
		return $results ? $results : array();
	}

	/**
	 * List published posts carrying a tag that the current viewer may read.
	 *
	 * @param int    $tag_id Tag ID.
	 * @param string $sort   'latest' or 'popular'.
	 * @param int    $limit  Max rows.
	 * @param int    $offset Pagination offset.
	 * @return object[] Post rows with space_slug joined.
	 */
	public static function list_by_tag_visible( int $tag_id, string $sort = 'latest', int $limit = 30, int $offset = 0 ): array {
		$table         = static::table();
		$spaces_tbl    = \Jetonomy\table( 'spaces' );
		$post_tags_tbl = \Jetonomy\table( 'post_tags' );
		$order_by      = 'popular' === $sort ? self::popular_order( 'p' ) : 'p.created_at DESC, p.id DESC';

		[ $gate_sql, $gate_params ] = self::viewer_gate_sql( 'sp' );

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.*, sp.slug AS space_slug FROM {$table} p
				 INNER JOIN {$post_tags_tbl} pt ON pt.post_id = p.id
				 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
				 WHERE pt.tag_id = %d AND p.status = 'publish'{$gate_sql}
				 ORDER BY {$order_by}
				 LIMIT %d OFFSET %d",
				$tag_id,
				...array_merge( $gate_params, array( $limit, $offset ) )
			)
		);
		return $results ? $results : array();
	}

	/**
	 * Count of what list_by_tag_visible() pages through, for the label and pager.
	 *
	 * The tag's stored post_count includes topics this viewer may not read, so a
	 * guest saw "40 topics" over an empty second page, and the number disclosed
	 * that private topics carry the tag.
	 *
	 * @param int $tag_id Tag ID.
	 * @return int
	 */
	public static function count_by_tag_visible( int $tag_id ): int {
		return self::count_by_tags_visible( array( $tag_id ) )[ $tag_id ] ?? 0;
	}

	/**
	 * Published topics per tag that the current viewer may read, in one query.
	 * The stored jt_tags.post_count counts every tagged topic, including ones in
	 * spaces the viewer cannot open, so it must never be shown as-is.
	 *
	 * @param int[] $tag_ids Tag IDs.
	 * @return array<int,int> tag_id => count (tags with none are absent).
	 */
	public static function count_by_tags_visible( array $tag_ids ): array {
		$tag_ids = array_values( array_filter( array_map( 'absint', $tag_ids ) ) );
		if ( ! $tag_ids ) {
			return array();
		}
		$table         = static::table();
		$spaces_tbl    = \Jetonomy\table( 'spaces' );
		$post_tags_tbl = \Jetonomy\table( 'post_tags' );
		$in            = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );

		[ $gate_sql, $gate_params ] = self::viewer_gate_sql( 'sp' );

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT pt.tag_id, COUNT(*) AS c FROM {$table} p
				 INNER JOIN {$post_tags_tbl} pt ON pt.post_id = p.id
				 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
				 WHERE pt.tag_id IN ({$in}) AND p.status = 'publish'{$gate_sql}
				 GROUP BY pt.tag_id",
				...array_merge( $tag_ids, $gate_params )
			)
		) ?: array();

		return array_map( 'intval', array_column( $rows, 'c', 'tag_id' ) );
	}

	/**
	 * Tags that have at least one topic the current viewer can read, with that
	 * visible count as post_count, paginated. A tag used only in spaces the
	 * viewer cannot read is left out entirely, so its name never leaks.
	 *
	 * @param string $sort   'popular' (visible count) or 'alphabetical'.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return array{rows:object[],total:int}
	 */
	public static function list_tags_visible( string $sort, int $limit, int $offset = 0 ): array {
		$table         = static::table();
		$tags_tbl      = \Jetonomy\table( 'tags' );
		$spaces_tbl    = \Jetonomy\table( 'spaces' );
		$post_tags_tbl = \Jetonomy\table( 'post_tags' );
		$order         = 'alphabetical' === $sort ? 't.name ASC' : 'post_count DESC, t.name ASC';

		[ $gate_sql, $gate_params ] = self::viewer_gate_sql( 'sp' );

		$from = "FROM {$post_tags_tbl} pt
				 INNER JOIN {$tags_tbl} t ON t.id = pt.tag_id
				 INNER JOIN {$table} p ON p.id = pt.post_id
				 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
				 WHERE p.status = 'publish'{$gate_sql}";

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names, a fixed ORDER BY and the gate's placeholders.
				"SELECT t.id, t.name, t.slug, COUNT(*) AS post_count {$from}
				 GROUP BY t.id
				 ORDER BY {$order} LIMIT %d OFFSET %d",
				...array_merge( $gate_params, array( $limit, $offset ) )
			)
		);
		$total = $gate_params
			? static::db()->get_var( static::db()->prepare( "SELECT COUNT(DISTINCT pt.tag_id) {$from}", ...$gate_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: static::db()->get_var( "SELECT COUNT(DISTINCT pt.tag_id) {$from}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$row->id         = (int) $row->id;
			$row->post_count = (int) $row->post_count;
		}

		return array(
			'rows'  => $rows ?: array(),
			'total' => (int) $total,
		);
	}

	/**
	 * Count a space's published ideas per roadmap status, as the viewer sees them.
	 *
	 * @param int  $space_id      Space ID.
	 * @param int  $user_id       Viewer (0 for guest).
	 * @param bool $is_privileged True for admins and space staff.
	 * @return array<string,int> Status => count.
	 */
	public static function count_ideas_by_status( int $space_id, int $user_id, bool $is_privileged ): array {
		$table  = static::table();
		$reader = self::space_reader_sql( $user_id, $is_privileged );

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT idea_status, COUNT(*) AS n FROM {$table}
				 WHERE space_id = %d AND status = 'publish' AND idea_status IS NOT NULL{$reader}
				 GROUP BY idea_status",
				$space_id
			)
		);

		$counts = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$counts[ (string) $row->idea_status ] = (int) $row->n;
		}
		return $counts;
	}

	/**
	 * List a space's top-voted published ideas in one roadmap status.
	 *
	 * @param int    $space_id      Space ID.
	 * @param string $idea_status   Roadmap status.
	 * @param int    $user_id       Viewer (0 for guest).
	 * @param bool   $is_privileged True for admins and space staff.
	 * @param int    $limit         Max rows.
	 * @return object[]
	 */
	public static function list_ideas_by_status( int $space_id, string $idea_status, int $user_id, bool $is_privileged, int $limit = 50 ): array {
		$table  = static::table();
		$reader = self::space_reader_sql( $user_id, $is_privileged );

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table}
				 WHERE space_id = %d AND status = 'publish' AND idea_status = %s{$reader}
				 ORDER BY vote_score DESC, created_at DESC, id DESC
				 LIMIT %d",
				$space_id,
				$idea_status,
				$limit
			)
		);
		return $results ? $results : array();
	}

	/**
	 * Top five published posts by votes, for the sidebar widget.
	 *
	 * All-time ranking, unlike list_trending()'s seven-day hot score; the
	 * widget has always shown it. Cached for five minutes per viewer class,
	 * because it filesorts on every page view.
	 *
	 * @param int $space_id Limit to one space, or 0 for every space the viewer can read.
	 * @return object[] Post rows with space_slug joined.
	 */
	public static function list_top_voted( int $space_id = 0 ): array {
		$user_id       = get_current_user_id();
		$is_privileged = $user_id && user_can( $user_id, 'manage_options' );
		if ( ! $is_privileged && $user_id && $space_id > 0 ) {
			$is_privileged = \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $user_id, $space_id );
		}

		// Every viewer-dependent clause is in the key: privileged viewers see
		// the same list, guests have no blocks, anyone else binds their own id.
		$bucket = $is_privileged ? 'priv' : ( $user_id ? "u{$user_id}" : 'guest' );

		return Cache::remember(
			"sidebar:trending:{$space_id}:{$bucket}",
			static function () use ( $space_id, $user_id, $is_privileged ) {
				$table      = static::table();
				$spaces_tbl = \Jetonomy\table( 'spaces' );
				// prepare() only with a value to bind: with no placeholder it raises a
				// _doing_it_wrong notice on every sidebar outside a space.
				$where  = $space_id > 0
					? static::db()->prepare( "p.status = 'publish' AND p.space_id = %d", $space_id )
					: "p.status = 'publish'";
				$where .= self::space_reader_sql( $user_id, $is_privileged, 'p' );

				// One space is already gated by the page that shows it; every
				// other page needs the viewer's space access as well.
				if ( 0 === $space_id ) {
					[ $space_sql, $space_params ] = Space::content_visibility_sql( $user_id, 'sp' );
					if ( '1=1' !== $space_sql ) {
						$where .= $space_params
							? static::db()->prepare( ' AND ' . $space_sql, ...$space_params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
							: ' AND ' . $space_sql;
					}
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = static::db()->get_results(
					"SELECT p.*, sp.slug AS space_slug FROM {$table} p
					 INNER JOIN {$spaces_tbl} sp ON sp.id = p.space_id
					 WHERE {$where}
					 ORDER BY p.vote_score DESC, p.reply_count DESC
					 LIMIT 5"
				);
				return $rows ? $rows : array();
			},
			300
		);
	}

	/**
	 * ORDER BY for every "Popular" topic list: votes, then replies, then
	 * newest. Most topics on a young community have no votes, so without the
	 * reply tiebreak Popular read exactly like Newest.
	 *
	 * @param string $alias Posts table alias, or '' for none.
	 * @return string
	 */
	public static function popular_order( string $alias = '' ): string {
		$col = '' !== $alias ? $alias . '.' : '';
		return "{$col}vote_score DESC, {$col}reply_count DESC, {$col}id DESC";
	}

	/**
	 * Private topics a given member may read, as SQL: the set-based twin of
	 * Permission_Engine::can_read_post()'s private branch, for lists built for
	 * someone other than the current viewer (the email digest). A private topic
	 * is kept for its author, the space's admins and moderators, and site admins.
	 * Space access itself is Space::content_visibility_sql()'s job.
	 *
	 * @param int    $user_id Reader (0 for guest).
	 * @param string $alias   Posts table alias.
	 * @return array{0:string,1:array} SQL fragment and its params.
	 */
	public static function private_reader_sql( int $user_id, string $alias = 'p' ): array {
		$col = '' !== $alias ? $alias . '.' : '';
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return array( '1=1', array() );
		}
		if ( $user_id <= 0 ) {
			return array( "{$col}is_private = 0", array() );
		}
		$members = \Jetonomy\table( 'space_members' );
		return array(
			"({$col}is_private = 0 OR {$col}author_id = %d OR {$col}space_id IN (SELECT space_id FROM {$members} WHERE user_id = %d AND role IN ('moderator','admin')))",
			array( $user_id, $user_id ),
		);
	}

	/**
	 * Filter for a space's post lists: private topics only for their author
	 * and space staff, and nothing from authors the viewer blocked.
	 *
	 * @param int    $user_id       Viewer (0 for guest).
	 * @param bool   $is_privileged True for admins and space staff.
	 * @param string $alias         Posts table alias, or '' for none.
	 * @return string Prepared SQL starting with ' AND', or ''.
	 */
	private static function space_reader_sql( int $user_id, bool $is_privileged, string $alias = '' ): string {
		$col = '' !== $alias ? $alias . '.' : '';
		$sql = '';

		if ( ! $is_privileged ) {
			$sql .= $user_id > 0
				? static::db()->prepare( " AND ({$col}is_private = 0 OR {$col}author_id = %d)", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: " AND {$col}is_private = 0";
		}

		[ $block_sql ] = BlockedUser::exclusion_sql( $user_id, $alias, 'author_id' );
		if ( '' !== $block_sql ) {
			$sql .= ' AND ' . $block_sql;
		}
		return $sql;
	}

	/**
	 * Filter for post lists that span spaces (author, tag): the viewer's
	 * space access, private topics, and authors the viewer blocked.
	 *
	 * @param string $space_alias Alias of the joined spaces table.
	 * @return array{0:string,1:array} SQL starting with ' AND' (or ''), and its params.
	 */
	private static function viewer_gate_sql( string $space_alias ): array {
		$viewer_id = get_current_user_id();

		[ $space_sql, $space_params ] = Space::content_visibility_sql( $viewer_id, $space_alias );
		[ $priv_sql, $priv_params ]   = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );
		[ $block_sql ]                = BlockedUser::exclusion_sql( $viewer_id, 'p', 'author_id' );

		$sql    = '';
		$params = array();
		if ( '1=1' !== $space_sql ) {
			$sql   .= ' AND ' . $space_sql;
			$params = $space_params;
		}
		if ( '' !== $priv_sql ) {
			$sql   .= ' AND ' . $priv_sql;
			$params = array_merge( $params, $priv_params );
		}
		if ( '' !== $block_sql ) {
			$sql .= ' AND ' . $block_sql;
		}
		return array( $sql, $params );
	}

	/**
	 * List trending posts using a time-decayed hot score.
	 *
	 * Score = (vote_score + reply_count * 2) / (hours_since_created + 2)^1.5
	 *
	 * This is the classic Reddit-style ranking: recent engagement wins over
	 * lifetime score so a post with 20 votes from today outranks a post with
	 * 200 votes from last year. The query restricts to a trailing window
	 * (default 7 days) so the math stays bounded and the index on created_at
	 * keeps it fast even at 10k+ posts per space.
	 *
	 * @param int      $limit      Max rows (default 5).
	 * @param int|null $space_id   Optional space filter. Null = site-wide.
	 * @param int      $window_days Trailing window in days (default 7).
	 * @return object[] Post rows with space_slug and space_title joined.
	 */
	public static function list_trending( int $limit = 5, ?int $space_id = null, int $window_days = 7 ): array {
		$limit       = max( 1, $limit );
		$window_days = max( 1, $window_days );
		$table       = static::table();
		$spaces_tbl  = \Jetonomy\table( 'spaces' );

		$where = "p.status = 'publish' AND p.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)";
		$args  = array( $window_days );

		if ( null !== $space_id && $space_id > 0 ) {
			$where .= ' AND p.space_id = %d';
			$args[] = $space_id;
		}

		// Space-visibility + per-post is_private gate so trending never surfaces
		// posts from private/hidden spaces (or private posts in public spaces)
		// to viewers who aren't members / authors.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 'sp' );
		[ $priv_sql, $priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );
		if ( '1=1' !== $space_vis_sql ) {
			$where .= ' AND ' . $space_vis_sql;
			$args   = array_merge( $args, $space_vis_params );
		}
		if ( '' !== $priv_sql ) {
			$where .= ' AND ' . $priv_sql;
			$args   = array_merge( $args, $priv_params );
		}

		// Hide posts from users the viewer has blocked. no-op for guests/no-blocks.
		[ $block_sql ] = BlockedUser::exclusion_sql( get_current_user_id(), 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			$where .= ' AND ' . $block_sql;
		}

		$args[] = $limit;

		$query = "SELECT p.*, sp.slug AS space_slug, sp.title AS space_title,
				(p.vote_score + p.reply_count * 2) / POW(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.5) AS hot_score
			FROM {$table} p
			LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
			WHERE {$where}
			ORDER BY hot_score DESC, p.created_at DESC
			LIMIT %d";

		$results = static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			static::db()->prepare( $query, ...$args )
		);
		return $results ? $results : array();
	}

	/**
	 * Global cross-space home feed, visibility-gated for $user_id.
	 *
	 * Unlike {@see self::list_trending()} (hot-only, unpaginated) this powers
	 * the app's home tab: paginated, sortable (hot|new|top), and gated in SQL
	 * so logged-out callers see only public-space posts while members see
	 * their full visibility set. Private posts (`is_private = 1`) are excluded
	 * for everyone — the home feed is a public surface, not a personal inbox.
	 *
	 * Big-site contract: LIMIT/OFFSET + a parallel COUNT(*), no per-row query.
	 * Sort windows lean on the existing `(status, created_at, space_id)` index;
	 * `top` filters on `created_at` (not `published_at`) so that index covers it
	 * without a new key.
	 *
	 * @since 1.6.0
	 *
	 * @param int    $user_id     Viewer (0 = logged-out → public spaces only).
	 * @param string $sort        'hot' | 'new' | 'top'.
	 * @param int    $limit       Page size (clamped 1..50).
	 * @param int    $offset      Offset for pagination.
	 * @param int    $window_days Only used by 'top' (default 7; 0 = all-time).
	 * @return array{posts:object[], total:int}
	 */
	public static function list_global_feed( int $user_id, string $sort = 'hot', int $limit = 20, int $offset = 0, int $window_days = 7 ): array {
		$limit       = max( 1, min( 50, $limit ) );
		$offset      = max( 0, $offset );
		$window_days = max( 0, $window_days );
		$sort        = in_array( $sort, array( 'hot', 'new', 'top' ), true ) ? $sort : 'hot';

		$table      = static::table();
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		$where      = "p.status = 'publish' AND p.is_private = 0";
		$where_args = array();

		// Member-or-public space gate (fails closed for guests).
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( $user_id, 'sp' );
		if ( '1=1' !== $vis_sql ) {
			$where     .= ' AND ' . $vis_sql;
			$where_args = array_merge( $where_args, $vis_params );
		}

		// Hide posts from users the viewer has blocked. no-op for guests/no-blocks.
		// Shared by the COUNT(*) and SELECT below (both read $where).
		[ $block_sql ] = BlockedUser::exclusion_sql( $user_id, 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			$where .= ' AND ' . $block_sql;
		}

		// `top` is scoped to a trailing window so the ranking stays meaningful.
		if ( 'top' === $sort && $window_days > 0 ) {
			$where       .= ' AND p.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
			$where_args[] = $window_days;
		}

		// Order is built from a fixed allow-list (not user input) so direct
		// interpolation is safe.
		switch ( $sort ) {
			case 'new':
				$order = 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC';
				break;
			case 'top':
				$order = self::popular_order( 'p' );
				break;
			default:
				$order = 'hot_score DESC, p.id DESC';
				break;
		}

		// hot/top ORDERING cache. hot_score is computed per row
		// and unindexable — every feed request full-scanned the visible post
		// set TWICE (COUNT + filesort). Cache the id ORDER + total only,
		// 90s, TTL-only (decay-ranked sorts make staleness invisible; 'new'
		// stays uncached — index-served and freshness-expected). Buckets:
		// 'guest' and 'admin' are shared classes; any other viewer gets a
		// per-user key (their member-space set + block list shape the WHERE).
		// LEAK-PROOF HYDRATION is the load-bearing part: the safety review
		// showed a cached id list re-fetched by bare id serves just-privated
		// / trashed / moved posts for the TTL — so the hydrate below re-runs
		// the FULL visibility predicate and drops misses, caching only the
		// ordering, never the rows.
		$feed_cacheable = in_array( $sort, array( 'hot', 'top' ), true );
		$feed_key       = '';
		if ( $feed_cacheable ) {
			$bucket = 'guest';
			if ( $user_id > 0 ) {
				$bucket = ( '1=1' === $vis_sql ) ? 'admin' : "u{$user_id}";
			}
			$feed_key = "feed:{$sort}:{$window_days}:{$limit}:{$offset}:{$bucket}";
			$cached   = Cache::get( $feed_key );
			if ( is_array( $cached ) && isset( $cached['ids'], $cached['total'] ) ) {
				$ids = array_map( 'intval', (array) $cached['ids'] );
				if ( empty( $ids ) ) {
					return array(
						'posts' => array(),
						'total' => (int) $cached['total'],
					);
				}

				$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$hydrate_sql  = "SELECT p.*, sp.slug AS space_slug, sp.title AS space_title,
						(p.vote_score + p.reply_count * 2) / POW(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.5) AS hot_score
					FROM {$table} p
					LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
					WHERE p.id IN ({$ph}) AND {$where}";
				$hydrate_rows = static::db()->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					static::db()->prepare( $hydrate_sql, ...array_merge( $ids, $where_args ) )
				) ?: array();
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				$by_id = array();
				foreach ( $hydrate_rows as $row ) {
					$by_id[ (int) $row->id ] = $row;
				}
				$posts = array();
				foreach ( $ids as $id ) {
					if ( isset( $by_id[ $id ] ) ) {
						$posts[] = $by_id[ $id ]; // Cached order; gate-failing ids drop.
					}
				}

				return array(
					'posts' => $posts,
					'total' => (int) $cached['total'],
				);
			}
		}

		// Total via a parallel COUNT(*) with the same WHERE (drives X-WP-Total).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$table} p LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id WHERE {$where}";
		$total     = (int) static::db()->get_var(
			empty( $where_args )
				? $count_sql
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: static::db()->prepare( $count_sql, ...$where_args )
		);

		$query = "SELECT p.*, sp.slug AS space_slug, sp.title AS space_title,
				(p.vote_score + p.reply_count * 2) / POW(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.5) AS hot_score
			FROM {$table} p
			LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
			WHERE {$where}
			ORDER BY {$order}
			LIMIT %d OFFSET %d";

		$query_args = array_merge( $where_args, array( $limit, $offset ) );
		$results    = static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			static::db()->prepare( $query, ...$query_args )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$results = $results ? $results : array();

		if ( $feed_cacheable ) {
			Cache::set(
				$feed_key,
				array(
					'ids'   => array_map( static fn( $r ) => (int) $r->id, $results ),
					'total' => $total,
				),
				90
			);
		}

		return array(
			'posts' => $results,
			'total' => $total,
		);
	}

	/**
	 * Close a post (prevent new replies).
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function close( int $id ): bool {
		return static::update( $id, array( 'is_closed' => 1 ) );
	}

	/**
	 * Pin (sticky) a post so it appears at the top of listings.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function pin( int $id ): bool {
		return static::update( $id, array( 'is_sticky' => 1 ) );
	}

	/**
	 * Count the currently pinned (sticky) published topics in a space.
	 *
	 * Used to enforce the per-space pin cap so the top of a space stays
	 * scarce. Indexed by `space_sticky_reply (space_id, is_sticky, ...)`.
	 *
	 * @param int $space_id Space ID.
	 * @return int Number of sticky published posts in the space.
	 */
	public static function count_sticky_in_space( int $space_id ): int {
		$table = static::table();
		return (int) static::db()->get_var(
			static::db()->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE space_id = %d AND is_sticky = 1 AND status = 'publish'",
				$space_id
			)
		);
	}

	/**
	 * Mark a reply as the accepted answer and resolve the post.
	 *
	 * @param int $id       Post ID.
	 * @param int $reply_id Reply ID to accept.
	 * @return bool
	 */
	public static function accept_reply( int $id, int $reply_id ): bool {
		return static::update(
			$id,
			array(
				'accepted_reply_id' => $reply_id,
				'is_resolved'       => 1,
			)
		);
	}

	/**
	 * Clear the accepted answer and mark the post unresolved (reverse of accept_reply()).
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function clear_accepted_reply( int $id ): bool {
		return static::update(
			$id,
			array(
				'accepted_reply_id' => null,
				'is_resolved'       => 0,
			)
		);
	}

	/**
	 * Roadmap statuses an idea (a post on a `type=ideas` space) can be in.
	 *
	 * Mirrors the `idea_status` enum in `class-schema.php`. Order is the
	 * canonical kanban progression - owners move ideas left to right
	 * (Planned -> In Progress -> Shipped), with Declined as the off-ramp.
	 * Ideas with no status assigned (NULL) live in the space's normal
	 * feed and do not appear on the roadmap kanban.
	 *
	 * @return string[]
	 */
	public static function valid_idea_statuses(): array {
		return array( 'planned', 'in_progress', 'shipped', 'declined' );
	}

	/**
	 * Set the roadmap status for an idea post.
	 *
	 * Caller is responsible for verifying the post belongs to a `type=ideas`
	 * space and the actor has moderator permission. The model only enforces
	 * that the new status is one of the canonical enum values; everything
	 * else (notification, activity log) is layered on top.
	 *
	 * @param int    $id     Post ID.
	 * @param string $status One of self::valid_idea_statuses().
	 * @return bool True on success, false if the status is invalid.
	 */
	public static function set_idea_status( int $id, string $status, int $actor_id = 0 ): bool {
		if ( ! in_array( $status, self::valid_idea_statuses(), true ) ) {
			return false;
		}

		$post = self::find( $id );
		if ( ! $post ) {
			return false;
		}
		$previous = (string) ( $post->idea_status ?? '' );

		// No-op transitions don't write or fire — idempotent.
		if ( $previous === $status ) {
			return true;
		}

		$result = static::update( $id, array( 'idea_status' => $status ) );
		if ( ! $result ) {
			return false;
		}

		/**
		 * Fires after an idea's roadmap status changes.
		 *
		 * Lives on the model so every write path — REST controller, the
		 * setup wizard's demo seeder, CLI, abilities, future imports —
		 * emits the same event. Listeners (activity log, notifier,
		 * gamification) hook here without caring how the transition was
		 * triggered.
		 *
		 * Re-fires from the REST controller are tolerated: the controller
		 * snapshots `$previous_status` before this call, so a duplicate
		 * fire would carry the same args. Until the controller is migrated
		 * to call the model directly, the controller's own fire stays so
		 * existing listeners (activity tracker, notifier) keep getting the
		 * `$actor_id` that the model doesn't always have.
		 *
		 * @param int    $post_id    Post ID.
		 * @param string $new_status The new status value.
		 * @param string $old_status The previous status value (or empty if unset).
		 * @param int    $actor_id   User ID of the actor who changed it (0 when called from a non-user context like the seeder).
		 * @param int    $author_id  Original post author user ID (0 if unset).
		 */
		do_action(
			'jetonomy_idea_status_changed',
			$id,
			$status,
			$previous,
			$actor_id,
			(int) ( $post->author_id ?? 0 )
		);

		return true;
	}

	/**
	 * Publish a scheduled post when its published_at time has arrived.
	 *
	 * @param int $id Post ID.
	 * @return bool True if published.
	 */
	/**
	 * Publish a draft post now, with the full create-post side-effects.
	 *
	 * Shared core for both the scheduled-cron publish and a manual "publish now"
	 * from the drafts UI / REST, so the two paths never drift.
	 *
	 * Publishing is when a draft is screened (Moderation_Service::screen_new_content),
	 * so under require_approval it goes to `pending`, not live.
	 *
	 * @param int  $id     Post ID.
	 * @param bool $screen False when a moderator is approving it (already reviewed).
	 * @return bool True if the draft left draft status, false if it was not a draft or a rule blocked it.
	 */
	public static function publish_draft( int $id, bool $screen = true ): bool {
		$post = static::find( $id );
		if ( ! $post || 'draft' !== ( $post->status ?? '' ) ) {
			return false;
		}

		$screened = array(
			'status' => 'publish',
			'flag'   => false,
		);
		if ( $screen ) {
			$screened = \Jetonomy\Moderation\Moderation_Service::screen_new_content(
				'post',
				array(
					'title'   => (string) $post->title,
					'content' => (string) $post->content,
				),
				(int) $post->space_id,
				(int) $post->author_id
			);
			if ( is_wp_error( $screened ) ) {
				return false;
			}
		}

		// Clear published_at on the same write so the post stops rendering a
		// "scheduled" badge and can never be re-selected by get_due_scheduled()
		// (which keys on `published_at IS NOT NULL`). $wpdb->update() emits a
		// real SQL NULL for a null value.
		//
		// The post's date becomes the moment it went live (the WordPress
		// convention for a scheduled post). Keeping the drafting time buried a
		// topic drafted last week under a week of newer ones in Latest, which
		// sorts by last_reply_at and displays created_at.
		$now = now();
		static::update(
			$id,
			array(
				'status'        => $screened['status'],
				'published_at'  => null,
				'created_at'    => $now,
				'last_reply_at' => $now,
				'updated_at'    => $now,
			)
		);

		// Do NOT increment post counts here. Post::update() already applies a
		// +1 delta on the draft->publish transition (see the $was_publish/
		// $is_publish branch in update()). Incrementing again double-counted
		// every scheduled post permanently. Mirrors the "intentionally removed
		// to prevent double-counting" note in class-posts-controller.php after
		// Post::create().

		// Fire the canonical post-creation side-effect hook so a post going live
		// runs the SAME listeners a normally-published post does: activity log,
		// subscriber notifications, @mention processing, auto-subscribe,
		// BuddyPress / FluentCommunity broadcasts, and Pro polls / custom-fields /
		// custom-badges / webhooks. A draft never fired this hook, so there is no
		// double-fire. The null request argument matches the established
		// programmatic fire in class-abilities.php (jetonomy_after_create_post is
		// documented as ($post_id, $space_id, $request|null)); request-reading
		// listeners no-op safely on null.
		do_action( 'jetonomy_after_create_post', $id, (int) $post->space_id, null );

		if ( $screened['flag'] ) {
			\Jetonomy\Moderation\Moderation_Service::auto_flag( 'post', $id );
		}

		return true;
	}

	public static function publish_scheduled( int $id ): bool {
		$post = static::find( $id );
		if ( ! $post || 'draft' !== ( $post->status ?? '' ) || empty( $post->published_at ) ) {
			return false;
		}

		if ( ! self::publish_draft( $id ) ) {
			return false;
		}

		// Keep the scheduled-specific extension point so an integration can still
		// distinguish "published on schedule" from "published immediately".
		/**
		 * Fires after a scheduled post is published by the scheduler.
		 *
		 * @since 1.0.0
		 *
		 * @param int $post_id  Post ID.
		 * @param int $space_id Space ID.
		 */
		do_action( 'jetonomy_scheduled_post_published', $id, (int) $post->space_id );

		return true;
	}

	/**
	 * List draft posts by a specific user.
	 *
	 * @param int $user_id Author user ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Pagination offset.
	 * @return object[]
	 */
	public static function list_drafts_by_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$table      = static::table();
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.*, s.slug AS space_slug, s.title AS space_title
				 FROM {$table} p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'draft'
				 ORDER BY p.created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $results ? $results : array();
	}

	/**
	 * Count the current user's draft posts.
	 *
	 * Companion to list_drafts_by_user() so the drafts view can compute
	 * accurate "Load More" pagination from the real total instead of the
	 * `count( $page ) >= $per_page` heuristic, which showed a phantom button
	 * when the draft count was an exact multiple of the page size.
	 *
	 * @param int $user_id Draft author.
	 * @return int Total draft count for the user.
	 */
	public static function count_drafts_by_user( int $user_id ): int {
		$table = static::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE author_id = %d AND status = 'draft'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Earliest schedule among scheduled drafts, or null when none is pending.
	 *
	 * Served by the status_created index (status = 'draft' is a small slice).
	 *
	 * @return string|null UTC 'Y-m-d H:i:s'.
	 */
	public static function next_scheduled_at(): ?string {
		$table = static::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = static::db()->get_var( "SELECT MIN(published_at) FROM {$table} WHERE status = 'draft' AND published_at IS NOT NULL" );
		return $next ? (string) $next : null;
	}

	/**
	 * A scheduled draft identical to one about to be created: same author,
	 * space, title and publish instant. Used to refuse a double-submitted
	 * schedule. Served by the author_created index.
	 *
	 * @param int    $author_id    Author user ID.
	 * @param int    $space_id     Space ID.
	 * @param string $title        Sanitized title.
	 * @param string $published_at UTC 'Y-m-d H:i:s'.
	 * @return object|null
	 */
	public static function find_scheduled_duplicate( int $author_id, int $space_id, string $title, string $published_at ): ?object {
		$table = static::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE author_id = %d AND space_id = %d AND status = 'draft' AND title = %s AND published_at = %s LIMIT 1",
				$author_id,
				$space_id,
				$title,
				$published_at
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Get posts that are scheduled and due for publishing.
	 *
	 * @param int $limit Maximum number of rows to return. 0 means no limit.
	 * @return object[]
	 */
	public static function get_due_scheduled( int $limit = 0 ): array {
		$table = static::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $limit > 0 ) {
			$results = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE status = 'draft' AND published_at IS NOT NULL AND published_at <= %s LIMIT %d",
					\Jetonomy\now(),
					$limit
				)
			);
		} else {
			$results = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE status = 'draft' AND published_at IS NOT NULL AND published_at <= %s",
					\Jetonomy\now()
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $results ? $results : array();
	}

	/**
	 * Merge a source post into a target post.
	 *
	 * Moves replies, votes, subscribers, bookmarks and the opening post (as a
	 * reply) from the source to the target, recalculates counts, and trashes
	 * the source. A private source is refused.
	 *
	 * @param int $source_id Source post ID (will be trashed).
	 * @param int $target_id Target post ID (receives replies).
	 * @return bool|\WP_Error True on success, false if either post is missing,
	 *                        WP_Error when the source is private.
	 */
	public static function merge_into( int $source_id, int $target_id ): bool|\WP_Error {
		$source = static::find( $source_id );
		$target = static::find( $target_id );

		if ( ! $source || ! $target ) {
			return false;
		}

		// Merging publishes the source's replies (and its opening post) in the
		// target, so a private topic would stop being private. The moderator
		// makes it public first, on purpose.
		if ( ! empty( $source->is_private ) ) {
			return new \WP_Error(
				'jetonomy_merge_private',
				sprintf( /* translators: %s: the singular Topic label, lowercase. */ __( 'This %s is private. Make it public before merging it.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
				array( 'status' => 400 )
			);
		}

		$replies_table = \Jetonomy\table( 'replies' );

		// One accepted answer per topic and the target's wins: a losing source
		// answer goes through the normal un-accept (hook, reputation taken back)
		// while it still sits in its own topic.
		$source_answer = (int) ( $source->accepted_reply_id ?? 0 );
		if ( $source_answer && (int) ( $target->accepted_reply_id ?? 0 ) ) {
			Reply::unaccept_as_answer( $source_answer, 0 );
		}

		// Move all replies from source to target.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$replies_table} SET post_id = %d WHERE post_id = %d",
				$target_id,
				$source_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// One accepted answer per topic: the target's own answer wins; if it
		// had none, the source's answer becomes the target's.
		$winner = (int) ( $target->accepted_reply_id ?? 0 ) ?: (int) ( $source->accepted_reply_id ?? 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		static::db()->query( static::db()->prepare( "UPDATE {$replies_table} SET is_accepted = 0 WHERE post_id = %d AND is_accepted = 1 AND id <> %d", $target_id, $winner ) );
		if ( $winner > 0 && $winner !== (int) ( $target->accepted_reply_id ?? 0 ) ) {
			static::accept_reply( $target_id, $winner );
		}
		// The source's answer now lives in the target; a restored source must not claim it.
		static::clear_accepted_reply( $source_id );

		// The rest of the source moves with it: votes,
		// subscribers and bookmarks. UPDATE IGNORE skips a member who already
		// has a row on the target (unique keys), and those leftovers are then
		// dropped, so nobody votes or subscribes twice.
		$votes = \Jetonomy\table( 'votes' );
		$subs  = \Jetonomy\table( 'subscriptions' );
		$marks = \Jetonomy\table( 'bookmarks' );
		$db    = static::db();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( $votes, $subs ) as $object_table ) {
			$db->query( $db->prepare( "UPDATE IGNORE {$object_table} SET object_id = %d WHERE object_type = 'post' AND object_id = %d", $target_id, $source_id ) );
			$db->query( $db->prepare( "DELETE FROM {$object_table} WHERE object_type = 'post' AND object_id = %d", $source_id ) );
		}
		$db->query( $db->prepare( "UPDATE IGNORE {$marks} SET post_id = %d WHERE post_id = %d", $target_id, $source_id ) );
		$db->query( $db->prepare( "DELETE FROM {$marks} WHERE post_id = %d", $source_id ) );
		$vote_score = (int) $db->get_var( $db->prepare( "SELECT COALESCE( SUM( value ), 0 ) FROM {$votes} WHERE object_type = 'post' AND object_id = %d", $target_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Keep the source's opening post readable in the target, as split does
		// for the thread it leaves: a real reply by the same author, dated when
		// it was written, headed so readers know where it came from. It keeps
		// the source's standing: an unapproved opening post arrives awaiting
		// approval, and spam or a draft is never copied.
		$donor_reply = 0;
		if ( in_array( $source->status, array( 'publish', 'pending' ), true ) ) {
			$donor_reply = Reply::create(
				array(
					'post_id'        => $target_id,
					'author_id'      => (int) $source->author_id,
					'skip_auto_join' => true,
					'is_anonymous'   => (int) ( $source->is_anonymous ?? 0 ),
					'status'         => (string) $source->status,
					'created_at'     => (string) $source->created_at,
					'content'        => sprintf(
						/* translators: %s: title of the topic that was merged in. */
						'<p><em>' . esc_html__( 'Merged from "%s":', 'jetonomy' ) . '</em></p>',
						esc_html( (string) $source->title )
					) . (string) $source->content,
				)
			);
		}

		$replies_t = \Jetonomy\table( 'replies' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_int( $donor_reply ) && $donor_reply > 0 ) {
			// The opening post's files travel with its text.
			$attachments = \Jetonomy\table( 'attachments' );
			$db->query( $db->prepare( "UPDATE {$attachments} SET object_type = 'reply', object_id = %d WHERE object_type = 'post' AND object_id = %d", $donor_reply, $source_id ) );
		}
		// The backdated donor reply must not move the thread's activity back.
		$last_reply_at = $db->get_var( $db->prepare( "SELECT MAX( created_at ) FROM {$replies_t} WHERE post_id = %d AND status = 'publish'", $target_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Recalculate reply count, score and last activity on target.
		$new_count = Reply::count_by_post( $target_id );
		static::update(
			$target_id,
			array(
				'reply_count'   => $new_count,
				'vote_score'    => $vote_score,
				'last_reply_at' => $last_reply_at,
				'updated_at'    => now(),
			)
		);
		Vote::reset_memo();
		Subscription::reset_memo();

		// Both threads changed under raw UPDATEs; the donor reply only busts the
		// target's cache when one was created.
		Reply::bust_thread( $target_id );
		Reply::bust_thread( $source_id );

		// Trash the source post. The publish→trash transition inside update()
		// already decrements the space's post_count and the author's post_count
		// — an explicit decrement here double-counted (-2 per merge), the same
		// bug class as Reply::split_to_post.
		static::update( $source_id, array( 'status' => 'trash' ) );

		/**
		 * Fires after one post is merged into another.
		 *
		 * @since 1.0.0
		 *
		 * @param int $source_id ID of the post that was merged away.
		 * @param int $target_id ID of the post that received the content.
		 */
		do_action( 'jetonomy_post_merged', $source_id, $target_id );

		return true;
	}


	// ── wp-admin listings ──

	/**
	 * Titles for a set of post ids, keyed by id.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,string>
	 */
	public static function titles_by_ids( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = static::db()->get_results( 'SELECT id, title FROM ' . static::table() . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = (string) $row->title;
		}
		return $out;
	}

	/**
	 * Published topics created since a moment.
	 *
	 * @param string   $since  UTC MySQL datetime.
	 * @param int|null $viewer Null = every topic; a user id (0 = guest) counts
	 *                         only topics in spaces that viewer can read.
	 * @return int
	 */
	public static function count_published_since( string $since, ?int $viewer = null ): int {
		if ( null === $viewer ) {
			return (int) static::db()->get_var(
				static::db()->prepare( 'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'publish' AND created_at >= %s", $since )
			);
		}
		[ $where, $values ] = Space::content_visibility_sql( $viewer, 's' );
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is literal SQL plus %d placeholders from content_visibility_sql().
				'SELECT COUNT(*) FROM ' . static::table() . ' p INNER JOIN ' . \Jetonomy\table( 'spaces' ) . " s ON s.id = p.space_id WHERE p.status = 'publish' AND p.created_at >= %s AND {$where}",
				$since,
				...$values
			)
		);
	}

	/**
	 * The space with the most new published topics since a moment.
	 *
	 * @param string $since UTC MySQL datetime.
	 * @return object|null {title, n}
	 */
	public static function busiest_space_since( string $since ): ?object {
		$spaces = \Jetonomy\table( 'spaces' );
		$row    = static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT s.title, COUNT(*) AS n FROM ' . static::table() . " p
				 INNER JOIN {$spaces} s ON s.id = p.space_id
				 WHERE p.status = 'publish' AND p.created_at >= %s
				 GROUP BY p.space_id, s.title ORDER BY n DESC LIMIT 1",
				$since
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Topics awaiting approval, newest first, with their space title.
	 *
	 * @param int   $limit  Max rows.
	 * @param int   $offset Offset.
	 * @param int[] $exclude_space_ids Spaces to leave out (site moderator scope).
	 * @return object[]
	 */
	public static function list_pending_with_space( int $limit, int $offset, array $exclude_space_ids = array() ): array {
		$spaces = \Jetonomy\table( 'spaces' );
		$skip   = array_values( array_unique( array_filter( array_map( 'absint', $exclude_space_ids ) ) ) );
		$not_in = $skip ? ' AND p.space_id NOT IN (' . implode( ', ', array_fill( 0, count( $skip ), '%d' ) ) . ')' : '';
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $not_in holds only %d placeholders.
				'SELECT p.*, s.title as space_title FROM ' . static::table() . " p
				 LEFT JOIN {$spaces} s ON s.id = p.space_id
				 WHERE p.status = 'pending'{$not_in}
				 ORDER BY p.created_at DESC
				 LIMIT %d OFFSET %d",
				...array_merge( $skip, array( $limit, $offset ) )
			)
		) ?: array();
	}

	/**
	 * The wp-admin Content screen's topics with space title and slug: newest
	 * first, or by a column sort (orderby: title, created_at, reply_count,
	 * view_count; order: ASC|DESC).
	 *
	 * @param array{space_id?:int,status?:string,search?:string,orderby?:string,order?:string} $filters Filters.
	 * @param int                                                                              $limit   Max rows.
	 * @param int                                                                              $offset  Offset.
	 * @return object[]
	 */
	public static function list_for_admin( array $filters, int $limit, int $offset ): array {
		[ $where, $args ] = self::admin_where( $filters );
		$spaces           = \Jetonomy\table( 'spaces' );
		$column           = in_array( $filters['orderby'] ?? '', array( 'title', 'created_at', 'reply_count', 'view_count' ), true ) ? $filters['orderby'] : 'created_at';
		$dir              = 'ASC' === strtoupper( (string) ( $filters['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is allow-listed, $dir is ASC/DESC.
				'SELECT p.*, s.title AS space_title, s.slug AS space_slug FROM ' . static::table() . " p
				 LEFT JOIN {$spaces} s ON s.id = p.space_id
				 WHERE {$where}
				 ORDER BY p.{$column} {$dir}, p.id {$dir}
				 LIMIT %d OFFSET %d",
				...array_merge( $args, array( $limit, $offset ) )
			)
		) ?: array();
	}

	/**
	 * How many topics list_for_admin() pages through.
	 *
	 * @param array{space_id?:int,status?:string,search?:string} $filters Filters.
	 * @return int
	 */
	public static function count_for_admin( array $filters ): int {
		[ $where, $args ] = self::admin_where( $filters );
		$sql              = 'SELECT COUNT(*) FROM ' . static::table() . " p WHERE {$where}";
		return (int) ( $args ? static::db()->get_var( static::db()->prepare( $sql, ...$args ) ) : static::db()->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only when $args.
	}

	/**
	 * Drafts waiting for their scheduled time (including overdue ones the
	 * hourly job has not published yet).
	 *
	 * @return int
	 */
	public static function count_scheduled(): int {
		return (int) static::db()->get_var( 'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'draft' AND published_at IS NOT NULL" );
	}

	/**
	 * WHERE for the wp-admin Content screen.
	 *
	 * "All" leaves out the trash (WordPress convention); "scheduled" is a
	 * draft with a published_at, overdue ones included so an owner can see
	 * what the hourly job has not published yet.
	 *
	 * @param array{space_id?:int,status?:string,search?:string} $f Filters.
	 * @return array{0:string,1:array}
	 */
	private static function admin_where( array $f ): array {
		$where  = '1=1';
		$args   = array();
		$status = (string) ( $f['status'] ?? 'all' );
		if ( ! empty( $f['space_id'] ) ) {
			$where .= ' AND p.space_id = %d';
			$args[] = (int) $f['space_id'];
		}
		if ( 'scheduled' === $status ) {
			$where .= " AND p.status = 'draft' AND p.published_at IS NOT NULL";
		} elseif ( 'all' !== $status ) {
			$where .= ' AND p.status = %s';
			$args[] = $status;
		} else {
			$where .= " AND p.status <> 'trash'";
		}
		if ( ! empty( $f['search'] ) ) {
			$where .= ' AND p.title LIKE %s';
			$args[] = '%' . static::db()->esc_like( (string) $f['search'] ) . '%';
		}
		return array( $where, $args );
	}

	/**
	 * Count behind {@see self::list_by_author()}, under the same viewer gate.
	 *
	 * @param int $user_id Author user ID.
	 * @return int
	 */
	public static function count_by_author( int $user_id ): int {
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		[ $gate_sql, $gate_params ] = self::viewer_gate_sql( 's' );

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . static::table() . " p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$gate_sql}",
				$user_id,
				...$gate_params
			)
		);
	}

	/**
	 * Ids of every topic an author wrote that is not in the trash.
	 *
	 * @param int $user_id Author user ID.
	 * @return int[]
	 */
	public static function ids_by_author( int $user_id ): array {
		return array_map(
			'intval',
			(array) static::db()->get_col(
				static::db()->prepare( 'SELECT id FROM ' . static::table() . " WHERE author_id = %d AND status != 'trash'", $user_id )
			)
		);
	}

	/**
	 * Topics whose body contains a text fragment (e.g. an upload's file path).
	 *
	 * @param string $fragment Literal text, LIKE-escaped here.
	 * @return int
	 */
	public static function count_content_containing( string $fragment ): int {
		return (int) static::db()->get_var(
			static::db()->prepare( 'SELECT COUNT(*) FROM ' . static::table() . ' WHERE content LIKE %s', '%' . static::db()->esc_like( $fragment ) . '%' )
		);
	}

	/**
	 * Latest or most popular published topics the current viewer may read,
	 * site-wide or in one space, with the space slug and title joined.
	 *
	 * @param int    $limit    Max rows.
	 * @param int    $space_id Space, or 0 for every space.
	 * @param string $sort     'latest', or anything else for popular.
	 * @return object[]
	 */
	public static function list_recent_visible( int $limit, int $space_id = 0, string $sort = 'latest' ): array {
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		$where = "p.status = 'publish'";
		$args  = array();
		if ( $space_id > 0 ) {
			$where .= ' AND p.space_id = %d';
			$args[] = $space_id;
		}

		[ $gate_sql, $gate_params ] = self::viewer_gate_sql( 'sp' );

		$order  = 'latest' === $sort ? 'p.created_at DESC, p.id DESC' : self::popular_order( 'p' );
		$args   = array_merge( $args, $gate_params );
		$args[] = $limit;

		$query = 'SELECT p.*, sp.slug AS space_slug, sp.title AS space_title
		          FROM ' . static::table() . " p
		          LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
		          WHERE {$where}{$gate_sql}
		          ORDER BY {$order}
		          LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return static::db()->get_results( static::db()->prepare( $query, ...$args ) ) ?: array();
	}

	/**
	 * SQL shared by the sitemap queries: published, non-private topics in
	 * active spaces a guest can list.
	 *
	 * @return string FROM ... WHERE fragment.
	 */
	private static function sitemap_from_where(): string {
		// The sitemap is crawler-facing, so the viewer is always a guest, and the
		// guest LISTING predicate (category visibility included) is what belongs in it.
		// s.visibility = 'public' stays explicit: the guest listing predicate also
		// admits private spaces when the owner turns on guest teasers, and their
		// topics must never reach a sitemap.
		[ $vis_s ] = Space::listing_visibility_sql( 0, 's' );
		return 'FROM ' . static::table() . ' p INNER JOIN ' . \Jetonomy\table( 'spaces' ) . " s ON p.space_id = s.id WHERE p.status = 'publish' AND p.is_private = 0 AND {$vis_s} AND s.visibility = 'public' AND s.status = 'active'";
	}

	/**
	 * Every sitemap topic id, ascending.
	 *
	 * @return int[]
	 */
	public static function sitemap_ids(): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) static::db()->get_col( 'SELECT p.id ' . self::sitemap_from_where() . ' ORDER BY p.id ASC' ) );
	}

	/**
	 * Newest change across sitemap topics (reply, edit or creation time).
	 *
	 * @return string UTC MySQL datetime, or '' when there are none.
	 */
	public static function sitemap_lastmod(): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) static::db()->get_var( "SELECT MAX(GREATEST(COALESCE(p.last_reply_at,'1970-01-01'), COALESCE(p.updated_at,'1970-01-01'), COALESCE(p.created_at,'1970-01-01'))) " . self::sitemap_from_where() );
	}

	/**
	 * One keyset page of sitemap topics after an id.
	 *
	 * @param int $start_after Last id of the previous page.
	 * @param int $limit       Page size.
	 * @return object[] Rows with id, post_slug, space_slug, space_id, last_reply_at, updated_at, created_at.
	 */
	public static function list_sitemap_page( int $start_after, int $limit ): array {
		return (array) static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT p.id, p.slug AS post_slug, s.slug AS space_slug, s.id AS space_id, p.last_reply_at, p.updated_at, p.created_at ' . self::sitemap_from_where() . ' AND p.id > %d ORDER BY p.id ASC LIMIT %d',
				$start_after,
				$limit
			)
		);
	}
}
