<?php
/**
 * Reply model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Reply extends Model {

	/**
	 * Hard ceiling on parent-chain walks in top_level_ancestor().
	 *
	 * Real threads never approach this — the tree builder renders 3 levels and
	 * flattens deeper ones. It exists so a cyclic parent_id (only reachable via
	 * direct DB edits or a future bug) can't hang a page render.
	 */
	private const MAX_ANCESTOR_HOPS = 10;

	/**
	 * Ordering a topic's replies render in when no ?rsort is supplied.
	 *
	 * page_of() computes a reply's page under THIS ordering, so it is a
	 * contract between the link-builder and the view, not a cosmetic default.
	 * \Jetonomy\reply_permalink() deliberately emits no ?rsort precisely so the
	 * page it computed is the page that renders. Changing this value without
	 * revisiting page_of() would silently mis-target every reply deep link;
	 * Journey_Tests::test_reply_deep_link_targets() fails if they disagree.
	 */
	public const DEFAULT_SORT = 'oldest';

	protected static function table_name(): string {
		return 'replies';
	}

	/**
	 * Create a new reply.
	 *
	 * Sets created_at and status defaults if absent. After a successful insert,
	 * increments the parent post's reply_count.
	 *
	 * @param array $data Column data.
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int|\WP_Error {
		/**
		 * Filter reply data before creation. Return WP_Error to abort.
		 *
		 * @param array $data      Column data.
		 * @param int   $author_id Author user ID.
		 * @param int   $post_id   Parent post ID.
		 */
		$data = apply_filters( 'jetonomy_before_create_reply', $data, $data['author_id'] ?? 0, $data['post_id'] ?? 0 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data = array_merge(
			array(
				'status'       => 'publish',
				'is_anonymous' => 0,
				'created_at'   => now(),
			),
			$data
		);

		$id = static::insert( $data );

		if ( $id > 0 ) {
			if ( ! empty( $data['post_id'] ) ) {
				Post::increment_reply_count( (int) $data['post_id'] );
			}
			if ( ! empty( $data['author_id'] ) ) {
				UserProfile::increment_reply_count( (int) $data['author_id'] );
			}

			// Auto-join the replier to the space on their first reply — same rules
			// as Post::create (open spaces only, no demotion of existing members).
			$parent_post = ! empty( $data['post_id'] ) ? Post::find( (int) $data['post_id'] ) : null;
			if ( $parent_post && ! empty( $data['author_id'] ) ) {
				self::maybe_auto_join_space( (int) $parent_post->space_id, (int) $data['author_id'] );
			}

			if ( 'publish' === ( $data['status'] ?? 'publish' ) ) {
				/**
				 * Fires when a reply enters or leaves `publish`.
				 *
				 * Mirrors `jetonomy_post_publish_transition` for the reply
				 * path — fired here for replies created directly as publish;
				 * Reply::update() fires it for later transitions.
				 *
				 * @since 1.5.0
				 *
				 * @param int    $reply_id   Reply ID.
				 * @param int    $delta      +1 entering publish, -1 leaving it.
				 * @param string $created_at Reply creation datetime (MySQL, UTC).
				 */
				do_action( 'jetonomy_reply_publish_transition', (int) $id, 1, (string) $data['created_at'] );
			}

			/**
			 * Fires after a reply is created.
			 *
			 * Mirrors `jetonomy_post_created` for the reply path. Listeners
			 * should inspect $context['status'] when status matters.
			 *
			 * @param int   $reply_id Inserted reply ID.
			 * @param int   $post_id  Parent post ID (0 if unset).
			 * @param int   $user_id  Author user ID (0 if unset).
			 * @param array $context  Inserted column data (status, parent_id,
			 *                        content, etc.).
			 */
			do_action(
				'jetonomy_reply_created',
				(int) $id,
				(int) ( $data['post_id'] ?? 0 ),
				(int) ( $data['author_id'] ?? 0 ),
				$data
			);
		}

		return $id;
	}

	/**
	 * Status-aware update. Mirrors Post::update — any transition in/out of
	 * `publish` adjusts the parent post's reply_count and the author's reply_count
	 * so every caller (REST, admin AJAX, abilities, CLI) stays counter-consistent.
	 */
	public static function update( int $id, array $data ): bool {
		$delta = 0;
		$reply = null;

		if ( array_key_exists( 'status', $data ) ) {
			$reply = self::find( $id );
			if ( $reply ) {
				$was_publish = 'publish' === ( $reply->status ?? '' );
				$is_publish  = 'publish' === (string) $data['status'];
				if ( $was_publish && ! $is_publish ) {
					$delta = -1;
				} elseif ( ! $was_publish && $is_publish ) {
					$delta = 1;
				}
			}
		}

		$result = parent::update( $id, $data );

		if ( 0 !== $delta && $reply ) {
			if ( ! empty( $reply->post_id ) ) {
				Post::increment_reply_count( (int) $reply->post_id, $delta );
			}
			if ( ! empty( $reply->author_id ) ) {
				UserProfile::increment_reply_count( (int) $reply->author_id, $delta );
			}

			/** This action is documented in includes/models/class-reply.php (Reply::create) */
			do_action( 'jetonomy_reply_publish_transition', $id, $delta, (string) ( $reply->created_at ?? '' ) );
		}

		return $result;
	}

	/**
	 * Add the replier to the parent post's space as a plain member if they aren't
	 * already a member. Only applies to open spaces.
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
	 * Delete a reply by ID.
	 *
	 * Fires `jetonomy_before_delete_reply` before deletion. Return WP_Error
	 * from the filter to abort.
	 *
	 * @param int $id Reply ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( int $id ): bool|\WP_Error {
		/**
		 * Filter whether a reply deletion should proceed. Return WP_Error to abort.
		 *
		 * @param bool $proceed Whether to proceed with deletion.
		 * @param int  $id      Reply ID.
		 */
		$proceed = apply_filters( 'jetonomy_before_delete_reply', true, $id );
		if ( is_wp_error( $proceed ) ) {
			return $proceed;
		}

		// Load before deletion so a hard delete reverses the same counters a
		// published reply incremented in create(). REST deletes go through
		// update( status => trash ), which already handles this; the direct
		// delete path (CLI content journey, QA fixtures, abilities) must mirror
		// it so post + author reply_count stay consistent across every delete
		// mechanism.
		$reply  = self::find( $id );
		$result = parent::delete( $id );

		if ( $result && $reply && 'publish' === ( $reply->status ?? '' ) ) {
			if ( ! empty( $reply->post_id ) ) {
				Post::increment_reply_count( (int) $reply->post_id, -1 );
			}
			if ( ! empty( $reply->author_id ) ) {
				UserProfile::increment_reply_count( (int) $reply->author_id, -1 );
			}

			/** This action is documented in includes/models/class-reply.php (Reply::create) */
			do_action( 'jetonomy_reply_publish_transition', $id, -1, (string) ( $reply->created_at ?? '' ) );
		}

		return $result;
	}

	/**
	 * List published replies for a post with sorting and cursor-based pagination.
	 *
	 * Sort options:
	 *   'oldest' — created_at ASC (default)
	 *   'newest' — created_at DESC
	 *   'best'   — vote_score DESC
	 *
	 * Cursor param:
	 *   $after > 0  — return only rows with id > $after (forward cursor)
	 *
	 * @param int    $post_id
	 * @param string $sort
	 * @param int    $limit
	 * @param int    $offset  Legacy offset; ignored when $after > 0.
	 * @param int    $after   Cursor: return replies after this reply ID.
	 * @return object[]
	 */
	public static function list_by_post( int $post_id, string $sort = self::DEFAULT_SORT, int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			$limit = \Jetonomy\replies_per_page();
		}
		$table = static::table();

		switch ( $sort ) {
			case 'newest':
				$order_by = 'created_at DESC, id DESC';
				break;

			case 'best':
				$order_by = 'vote_score DESC, id ASC';
				break;

			case 'oldest':
			default:
				$order_by = 'created_at ASC, id ASC';
				break;
		}

		/**
		 * Filter reply query parameters before execution.
		 *
		 * @param array $args    Query parameters: order_by, limit, offset, after.
		 * @param int   $post_id Parent post ID being queried.
		 */
		$args = apply_filters(
			'jetonomy_replies_query_args',
			array(
				'order_by' => $order_by,
				'limit'    => $limit,
				'offset'   => $offset,
				'after'    => $after,
			),
			$post_id
		);

		$order_by = $args['order_by'];
		$limit    = (int) $args['limit'];
		$offset   = (int) $args['offset'];
		$after    = (int) $args['after'];

		// Blocked authors are TOMBSTONED here, not SQL-excluded.
		//
		// This is the flat list REST clients (and the mobile app) consume, and
		// they re-nest it client-side on parent_id. Dropping a blocked author's
		// row would leave every child of theirs pointing at a parent that isn't
		// in the payload — an innocent third party's reply then either vanishes
		// or silently re-parents to the thread root, detached from the
		// conversation it was answering. Same reasoning as build_tree(): keep
		// the node so the subtree survives, and empty its content instead. The
		// row count also stays stable, so pagination and `has_more` don't shift
		// per viewer.
		$blocked_ids = BlockedUser::blocked_ids( get_current_user_id() );

		// Cursor: prefer id-based over offset when $after is provided.
		if ( $after > 0 ) {
			$rows = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE post_id = %d AND status = 'publish' AND id > %d ORDER BY {$order_by} LIMIT %d",
					$post_id,
					$after,
					$limit
				)
			) ?: array();
		} else {
			$rows = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE post_id = %d AND status = 'publish' ORDER BY {$order_by} LIMIT %d OFFSET %d",
					$post_id,
					$limit,
					$offset
				)
			) ?: array();
		}

		foreach ( $rows as $row ) {
			self::apply_block_tombstone( $row, $blocked_ids );
		}

		return $rows;
	}

	/**
	 * Mark a reply as the accepted answer.
	 *
	 * Clears any previously-accepted reply on the same post before marking
	 * the new one, so a Q&A post always has at most one accepted reply.
	 *
	 * @param int $id Reply ID.
	 */
	public static function mark_accepted( int $id ): void {
		$reply = static::find( $id );
		if ( ! $reply ) {
			return;
		}

		$post_id = (int) $reply->post_id;
		if ( $post_id > 0 ) {
			// Clear any other accepted reply on this post first. Worst case
			// between the two updates is zero accepted replies, which is a
			// safe state. Two accepted replies is the broken state we prevent.
			static::db()->update(
				static::table(),
				array( 'is_accepted' => 0 ),
				array(
					'post_id'     => $post_id,
					'is_accepted' => 1,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}

		static::update( $id, array( 'is_accepted' => 1 ) );
	}

	/**
	 * Clear the accepted flag on a reply (reverse of mark_accepted()).
	 *
	 * @param int $id Reply ID.
	 */
	public static function unmark_accepted( int $id ): void {
		static::update( $id, array( 'is_accepted' => 0 ) );
	}

	/**
	 * Return the highest reply id for a post (1.4.0 C.5 fallback for posts
	 * whose Post row didn't keep last_reply_id in sync).
	 *
	 * @param int $post_id
	 * @return int 0 when the post has no replies.
	 */
	public static function latest_id_for_post( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT MAX(id) FROM ' . static::table() . ' WHERE post_id = %d',
				$post_id
			)
		);
	}

	/**
	 * Count replies for a given post.
	 *
	 * @param int $post_id
	 * @return int
	 */
	public static function count_by_post( int $post_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE post_id = %d',
				$post_id
			)
		);
	}

	/**
	 * List replies in the given moderation statuses, newest first, paginated.
	 *
	 * Mirrors Post::list_by_status() so the REST moderation queue and the
	 * wp-admin Moderation screen share one implementation. Served by the
	 * status_created (status, created_at) index.
	 *
	 * @param string[] $statuses One or more of publish|pending|draft|spam|trash.
	 * @param int      $limit    Max rows.
	 * @param int      $offset   Pagination offset.
	 * @return object[]
	 */
	public static function list_by_status( array $statuses, int $limit = 20, int $offset = 0 ): array {
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return array();
		}
		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( $statuses, array( $limit, $offset ) );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table trusted, $placeholders is a list of %s.
				"SELECT * FROM {$table} WHERE status IN ({$placeholders}) ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$params
			)
		) ?: array();
	}

	/**
	 * Count replies in the given moderation statuses via COUNT(*).
	 *
	 * @param string[] $statuses
	 * @return int
	 */
	public static function count_by_status( array $statuses ): int {
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return 0;
		}
		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table trusted, $placeholders is a list of %s.
				"SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})",
				...$statuses
			)
		);
	}

	/**
	 * Get replies as a threaded tree.
	 *
	 * Returns top-level replies with nested 'children' arrays.
	 * Max depth: 3 levels.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $sort    Sort order: 'oldest', 'newest', or 'best'.
	 * @param int    $limit   Max top-level replies to return (0 = all).
	 * @param int    $offset  Offset for top-level replies.
	 * @return array Threaded reply tree.
	 */
	public static function get_threaded( int $post_id, string $sort = self::DEFAULT_SORT, int $limit = 0, int $offset = 0 ): array {
		// Fetch ALL replies for this post (we need full tree to build hierarchy).
		//
		// `id ASC` is a required tiebreak, not decoration: page_of() breaks
		// created_at ties on id, and if this query left ties to MySQL's
		// undefined ordering the two could disagree and land a deep link on
		// the wrong page. Ties are not hypothetical — bulk imports (bbPress,
		// wpForo) routinely write a whole thread with one timestamp.
		$all = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE post_id = %d AND status = 'publish' ORDER BY created_at ASC, id ASC",
				$post_id
			)
		);

		if ( empty( $all ) ) {
			return array();
		}

		// Build tree: group by parent_id.
		$by_parent = array();
		foreach ( $all as $reply ) {
			$pid                 = (int) ( $reply->parent_id ?? 0 );
			$by_parent[ $pid ][] = $reply;
		}

		// Blocked-author ids for the current viewer, loaded ONCE for the whole
		// tree (not per-row) — see build_tree()'s tombstone step.
		$blocked_ids = BlockedUser::blocked_ids( get_current_user_id() );

		// Recursively attach children (max 3 levels).
		$tree = self::build_tree( $by_parent, 0, 0, 3, $blocked_ids );

		// Sort top-level based on sort param.
		if ( 'newest' === $sort ) {
			$tree = array_reverse( $tree );
		} elseif ( 'best' === $sort ) {
			usort( $tree, fn( $a, $b ) => (int) $b->vote_score - (int) $a->vote_score );
		}

		// Apply offset/limit to top-level replies only.
		if ( $limit > 0 ) {
			$tree = array_slice( $tree, $offset, $limit );
		}

		return $tree;
	}

	/**
	 * Whether a reply id appears anywhere in a threaded tree (any nesting depth).
	 *
	 * Canonical membership test for a `get_threaded()` result so callers don't
	 * each hand-roll a recursive walker. Used by the single-post view to decide
	 * whether the Q&A accepted-answer callout is a duplicate of an inline reply
	 * on the current page.
	 *
	 * @param array $tree Threaded nodes (each may carry a ->children array).
	 * @param int   $id   Reply id to search for.
	 * @return bool
	 */
	public static function tree_contains( array $tree, int $id ): bool {
		foreach ( $tree as $node ) {
			if ( (int) $node->id === $id ) {
				return true;
			}
			if ( ! empty( $node->children ) && self::tree_contains( $node->children, $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively build threaded tree from grouped replies.
	 *
	 * Blocked-author replies are tombstoned, NOT dropped: dropping a node
	 * whose id is some other (innocent) reply's parent_id would orphan that
	 * entire subtree, silently hiding replies from users the viewer never
	 * blocked. See self::apply_block_tombstone().
	 *
	 * @param array $by_parent   Replies grouped by parent_id.
	 * @param int   $parent_id   Current parent ID.
	 * @param int   $depth       Current depth.
	 * @param int   $max_depth   Maximum nesting depth.
	 * @param int[] $blocked_ids Viewer's blocked author ids (loaded once by the caller).
	 * @return array Nested reply nodes.
	 */
	private static function build_tree( array &$by_parent, int $parent_id, int $depth, int $max_depth, array $blocked_ids = array() ): array {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $by_parent[ $parent_id ] as $reply ) {
			$reply->depth    = $depth;
			$reply->children = array();

			self::apply_block_tombstone( $reply, $blocked_ids );

			if ( $depth < $max_depth ) {
				$reply->children = self::build_tree( $by_parent, (int) $reply->id, $depth + 1, $max_depth, $blocked_ids );
			} else {
				// At max depth, flatten further children at the same level.
				$reply->children = self::build_tree( $by_parent, (int) $reply->id, $depth, $max_depth, $blocked_ids );
			}

			$nodes[] = $reply;
		}

		return $nodes;
	}

	/**
	 * Mark + scrub a reply row authored by a user the viewer has blocked.
	 *
	 * Sets `is_blocked_author = true` and nulls the content fields SERVER-SIDE
	 * so blocked text never reaches the client — the reply-card partial then
	 * renders a tombstone ("Content hidden — you blocked this user") instead
	 * of the body. `author_id` is deliberately left intact: the template needs
	 * it to render the Unblock affordance and to keep avatar/permission checks
	 * from erroring on a null author.
	 *
	 * Shared by get_threaded()'s tree builder and the off-page Q&A
	 * accepted-answer callout (single-post.php), which fetches its reply via
	 * Reply::find() instead of the tree and must apply the same treatment.
	 *
	 * @param object $reply       Reply row, mutated in place.
	 * @param int[]  $blocked_ids Viewer's blocked author ids.
	 */
	public static function apply_block_tombstone( object $reply, array $blocked_ids ): void {
		$reply->is_blocked_author = in_array( (int) $reply->author_id, $blocked_ids, true );

		if ( $reply->is_blocked_author ) {
			$reply->content       = '';
			$reply->content_plain = '';
		}
	}

	/**
	 * List published replies by a specific user, with parent post info.
	 *
	 * Anonymous replies (is_anonymous = 1) are always excluded, including from
	 * the author's own profile: surfacing them here — even to the author
	 * themselves — would deanonymize the reply by correlating it to this
	 * identity, defeating the anonymity the space/activity views already mask.
	 *
	 * @param int $user_id  Author user ID.
	 * @param int $limit    Max rows.
	 * @param int $offset   Pagination offset.
	 * @return object[]
	 */
	public static function list_by_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$replies_tbl = static::table();
		$posts_tbl   = \Jetonomy\table( 'posts' );
		$spaces_tbl  = \Jetonomy\table( 'spaces' );

		// Space-visibility + per-post is_private gate on the PARENT post: a
		// reply in a private/hidden space (or under a private post) must not
		// surface to non-member / non-author viewers of this user's profile.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 'sp' );
		[ $priv_sql, $priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );

		$gate_sql    = '';
		$gate_params = array();
		if ( '1=1' !== $space_vis_sql ) {
			$gate_sql   .= ' AND ' . $space_vis_sql;
			$gate_params = array_merge( $gate_params, $space_vis_params );
		}
		if ( '' !== $priv_sql ) {
			$gate_sql   .= ' AND ' . $priv_sql;
			$gate_params = array_merge( $gate_params, $priv_params );
		}

		// Hide replies from users the viewer has blocked. no-op for guests/no-blocks.
		[ $block_sql ] = BlockedUser::exclusion_sql( get_current_user_id(), 'r', 'author_id' );
		if ( '' !== $block_sql ) {
			$gate_sql .= ' AND ' . $block_sql;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// post_content_plain is selected so feed-space parent posts
				// (which store an empty title by design — 1.4.3 WS1) can
				// still render a human-readable "on <excerpt>" string in
				// the user-profile Replies tab.
				"SELECT r.*, p.title AS post_title, p.slug AS post_slug, p.content_plain AS post_content_plain, sp.slug AS space_slug, sp.title AS space_title
				 FROM {$replies_tbl} r
				 LEFT JOIN {$posts_tbl} p ON p.id = r.post_id
				 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
				 WHERE r.author_id = %d AND r.status = 'publish' AND r.is_anonymous = 0{$gate_sql}
				 ORDER BY r.created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				...array_merge( $gate_params, array( $limit, $offset ) )
			)
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count top-level replies (parent_id IS NULL or 0).
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function count_top_level( int $post_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE post_id = %d AND (parent_id IS NULL OR parent_id = 0) AND status = 'publish'",
				$post_id
			)
		);
	}

	/**
	 * Walk up to the top-level ancestor of a reply.
	 *
	 * A deep link to a nested reply has to page to wherever its top-level
	 * ancestor sits, because pagination slices the TOP-LEVEL list only
	 * (get_threaded()) — a child is rendered with its root, never on a page
	 * of its own.
	 *
	 * Iterative primary-key lookups rather than a recursive CTE: CTEs need
	 * MySQL 8 / MariaDB 10.2 and WordPress still supports 5.7. The chain is
	 * short in practice (build_tree() renders 3 levels and flattens below
	 * that), and MAX_ANCESTOR_HOPS bounds it regardless of stored depth so a
	 * corrupted parent cycle can never spin here.
	 *
	 * @param object $reply Reply row.
	 * @return object|null Top-level ancestor (may be $reply itself), or null if the chain is broken.
	 */
	public static function top_level_ancestor( object $reply ): ?object {
		$current = $reply;
		for ( $hops = 0; $hops < self::MAX_ANCESTOR_HOPS; $hops++ ) {
			$parent_id = (int) ( $current->parent_id ?? 0 );
			if ( ! $parent_id ) {
				return $current;
			}
			$parent = static::find( $parent_id );
			if ( ! $parent ) {
				// Orphaned child (parent deleted). It renders at top level in
				// the tree builder, so treat it as its own root.
				return $current;
			}
			$current = $parent;
		}
		return null;
	}

	/**
	 * Which page of top-level replies a given reply appears on.
	 *
	 * Counts siblings ordered before the reply's top-level ancestor in SQL —
	 * one indexed COUNT (post_created covers post_id + created_at) rather
	 * than loading the thread. This runs per notification and per deep link,
	 * on topics that can hold thousands of replies.
	 *
	 * ORDERING CONTRACT — read before changing this query. The position is
	 * computed under self::DEFAULT_SORT only (created_at ASC, id ASC as a
	 * deterministic tiebreak), matching get_threaded()'s default. That is
	 * sound only because \Jetonomy\reply_permalink() emits no ?rsort, so the
	 * target page always renders in this same order. It must stay that way:
	 *
	 *   - 'newest' reverses the top-level list, so a reply's page number is
	 *     different (often mirrored) under it.
	 *   - 'best' orders by vote_score, which CHANGES AS PEOPLE VOTE. A page
	 *     number computed under 'best' is not merely different, it rots —
	 *     yesterday's link points somewhere else today. A permalink must be
	 *     stable, so it pins the ordering by omitting the param rather than
	 *     inheriting whatever the linking reader happened to be viewing.
	 *
	 * Journey_Tests::test_reply_deep_link_targets() asserts this function and
	 * the rendered page still agree, so a change to either side fails loudly
	 * instead of silently mis-targeting every reply link.
	 *
	 * @param int $reply_id Reply ID (top-level or nested).
	 * @param int $per_page Top-level replies per page.
	 * @return int 1-based page number; 1 when unresolvable.
	 */
	public static function page_of( int $reply_id, int $per_page ): int {
		$per_page = max( 1, $per_page );

		$reply = static::find( $reply_id );
		if ( ! $reply ) {
			return 1;
		}

		$anchor = self::top_level_ancestor( $reply );
		if ( ! $anchor ) {
			return 1;
		}

		// 1-based position of the anchor among its published top-level siblings.
		$position = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table()
					. " WHERE post_id = %d AND (parent_id IS NULL OR parent_id = 0) AND status = 'publish'"
					. ' AND ( created_at < %s OR ( created_at = %s AND id <= %d ) )',
				(int) $anchor->post_id,
				$anchor->created_at,
				$anchor->created_at,
				(int) $anchor->id
			)
		);

		// 0 means the anchor itself is not published (unapproved/trashed) — it
		// won't render on any page, so send the reader to page 1 rather than
		// page 0.
		if ( $position < 1 ) {
			return 1;
		}

		return (int) ceil( $position / $per_page );
	}

	/**
	 * Split a reply (and its children) into a new topic.
	 *
	 * Creates a new post from the reply content, moves child replies to
	 * the new post, deletes the original reply from the source post,
	 * and updates all denormalized counters.
	 *
	 * @param int    $reply_id       The reply to split.
	 * @param string $new_title      Title for the new post.
	 * @param int    $target_space_id Space for the new post (0 = same space as source post).
	 * @return int New post ID, or 0 on failure.
	 */
	public static function split_to_topic( int $reply_id, string $new_title, int $target_space_id = 0 ): int {
		$reply = static::find( $reply_id );
		if ( ! $reply ) {
			return 0;
		}

		$source_post = Post::find( (int) $reply->post_id );
		if ( ! $source_post ) {
			return 0;
		}

		if ( $target_space_id <= 0 ) {
			$target_space_id = (int) $source_post->space_id;
		}

		// Create new post from reply content.
		$new_post_id = Post::create(
			array(
				'space_id'      => $target_space_id,
				'author_id'     => (int) $reply->author_id,
				'title'         => $new_title,
				'slug'          => sanitize_title( $new_title ),
				'content'       => $reply->content ?? '',
				'content_plain' => $reply->content_plain ?? wp_strip_all_tags( $reply->content ?? '' ),
				'type'          => $source_post->type ?? 'topic',
				'status'        => 'publish',
				'created_at'    => $reply->created_at ?? now(),
			)
		);

		if ( is_wp_error( $new_post_id ) || ! $new_post_id ) {
			return 0;
		}

		// Move child replies (where parent_id = reply_id) to new post.
		$table = static::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$moved_count = (int) static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET post_id = %d, parent_id = 0 WHERE post_id = %d AND parent_id = %d",
				$new_post_id,
				(int) $reply->post_id,
				$reply_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Update reply count on new post.
		if ( $moved_count > 0 ) {
			Post::update( $new_post_id, array( 'reply_count' => $moved_count ) );
		}

		// Delete original reply from source post (soft-delete).
		static::update( $reply_id, array( 'status' => 'trash' ) );

		// Decrement source post reply count (the split reply + its children).
		Post::increment_reply_count( (int) $reply->post_id, -1 * ( 1 + $moved_count ) );

		do_action( 'jetonomy_reply_split', $reply_id, $new_post_id, (int) $reply->post_id );

		return $new_post_id;
	}
}
