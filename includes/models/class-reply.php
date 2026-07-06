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
				'status'     => 'publish',
				'created_at' => now(),
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
	public static function list_by_post( int $post_id, string $sort = 'oldest', int $limit = -1, int $offset = 0, int $after = 0 ): array {
		if ( -1 === $limit ) {
			$settings = get_option( 'jetonomy_settings', array() );
			$limit    = (int) ( $settings['replies_per_page'] ?? 30 );
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

		// Cursor: prefer id-based over offset when $after is provided.
		if ( $after > 0 ) {
			return static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE post_id = %d AND status = 'publish' AND id > %d ORDER BY {$order_by} LIMIT %d",
					$post_id,
					$after,
					$limit
				)
			) ?: array();
		}

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE post_id = %d AND status = 'publish' ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$post_id,
				$limit,
				$offset
			)
		) ?: array();
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
	public static function get_threaded( int $post_id, string $sort = 'oldest', int $limit = 0, int $offset = 0 ): array {
		// Fetch ALL replies for this post (we need full tree to build hierarchy).
		$all = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE post_id = %d AND status = 'publish' ORDER BY created_at ASC",
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

		// Recursively attach children (max 3 levels).
		$tree = self::build_tree( $by_parent, 0, 0, 3 );

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
	 * @param array $by_parent Replies grouped by parent_id.
	 * @param int   $parent_id Current parent ID.
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Maximum nesting depth.
	 * @return array Nested reply nodes.
	 */
	private static function build_tree( array &$by_parent, int $parent_id, int $depth, int $max_depth ): array {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $by_parent[ $parent_id ] as $reply ) {
			$reply->depth    = $depth;
			$reply->children = array();

			if ( $depth < $max_depth ) {
				$reply->children = self::build_tree( $by_parent, (int) $reply->id, $depth + 1, $max_depth );
			} else {
				// At max depth, flatten further children at the same level.
				$reply->children = self::build_tree( $by_parent, (int) $reply->id, $depth, $max_depth );
			}

			$nodes[] = $reply;
		}

		return $nodes;
	}

	/**
	 * List published replies by a specific user, with parent post info.
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
				 WHERE r.author_id = %d AND r.status = 'publish'{$gate_sql}
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
