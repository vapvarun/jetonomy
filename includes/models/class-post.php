<?php
/**
 * Post model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

/**
 * Represents a forum post (topic, question, idea, etc.).
 */
class Post extends Model {

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
	public static function update( int $id, array $data ): bool {
		$delta = 0;
		$post  = null;

		if ( array_key_exists( 'status', $data ) ) {
			$post = self::find( $id );
			if ( $post ) {
				$was_publish = 'publish' === ( $post->status ?? '' );
				$is_publish  = 'publish' === (string) $data['status'];
				if ( $was_publish && ! $is_publish ) {
					$delta = -1;
				} elseif ( ! $was_publish && $is_publish ) {
					$delta = 1;
				}
			}
		}

		$result = parent::update( $id, $data );

		if ( 0 !== $delta && $post ) {
			if ( ! empty( $post->space_id ) ) {
				Space::increment_post_count( (int) $post->space_id, $delta );
			}
			if ( ! empty( $post->author_id ) ) {
				UserProfile::increment_post_count( (int) $post->author_id, $delta );
			}

			/** This action is documented in includes/models/class-post.php (Post::create) */
			do_action( 'jetonomy_post_publish_transition', $id, $delta, (string) ( $post->created_at ?? '' ) );
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

		return parent::delete( $id );
	}

	/**
	 * Find a post by its slug.
	 *
	 * @param string $slug Post slug.
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ): ?object {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s',
				$slug
			)
		);
		return $row ? $row : null;
	}

	/**
	 * List published posts in a space with sorting and cursor-based pagination.
	 *
	 * Sort options:
	 *   'latest'      — sticky posts first, then last_reply_at DESC
	 *   'popular'     — vote_score DESC
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

		switch ( $sort ) {
			case 'popular':
				$order_by = 'is_sticky DESC, vote_score DESC';
				break;

			case 'oldest':
				$order_by = 'is_sticky DESC, created_at ASC';
				break;

			case 'newest':
				$order_by = 'is_sticky DESC, created_at DESC';
				break;

			case 'unanswered':
				$order_by = 'is_sticky DESC, created_at DESC';
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
				$order_by = 'is_sticky DESC, last_reply_at DESC';
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

		// Cursor: prefer id-based over offset when $after is provided.
		if ( $after > 0 ) {
			$params  = array( $space_id, $after, $limit );
			$results = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} AND id > %d ORDER BY {$order_by} LIMIT %d",
					...$params
				)
			);
			return $results ? $results : array();
		}

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

		switch ( $sort ) {
			case 'popular':
				$order_by = 'is_sticky DESC, vote_score DESC';
				break;

			case 'oldest':
				$order_by = 'is_sticky DESC, created_at ASC';
				break;

			case 'newest':
				$order_by = 'is_sticky DESC, created_at DESC';
				break;

			case 'unanswered':
				$order_by = 'is_sticky DESC, created_at DESC';
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
				$order_by = 'is_sticky DESC, last_reply_at DESC';
				break;
		}

		// Visibility filter: privileged users see everything, others see public + own private.
		if ( ! $is_privileged ) {
			if ( $user_id > 0 ) {
				$extra_where .= static::db()->prepare( ' AND (is_private = 0 OR author_id = %d)', $user_id );
			} else {
				$extra_where .= ' AND is_private = 0';
			}
		}

		if ( $after > 0 ) {
			$params  = array( $space_id, $after, $limit );
			$results = static::db()->get_results(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where} AND id > %d ORDER BY {$order_by} LIMIT %d",
					...$params
				)
			);
			return $results ? $results : array();
		}

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
		$table       = static::table();
		$extra_where = '';

		if ( 'unanswered' === $sort ) {
			$_jt_space   = Space::find( $space_id );
			$extra_where = ( $_jt_space && 'qa' === ( $_jt_space->type ?? '' ) )
				? ' AND accepted_reply_id IS NULL'
				: ' AND reply_count = 0';
		}

		if ( ! $is_privileged ) {
			if ( $user_id > 0 ) {
				$extra_where .= static::db()->prepare( ' AND (is_private = 0 OR author_id = %d)', $user_id );
			} else {
				$extra_where .= ' AND is_private = 0';
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE space_id = %d AND status = 'publish'{$extra_where}",
				$space_id
			)
		);
	}

	/**
	 * List posts in the given moderation statuses, newest first, paginated.
	 *
	 * Shared by the REST moderation queue and the wp-admin Moderation screen so
	 * both read one implementation instead of duplicating raw SQL. Served by the
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
	 * Count posts in the given moderation statuses via COUNT(*) (no row load).
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
	 * Adjust reply_count and update last_reply_at and updated_at.
	 *
	 * Pass a negative value to decrement. Uses GREATEST() to prevent
	 * the counter from going below zero.
	 *
	 * @param int $id Post ID.
	 * @param int $by Amount to adjust (default +1).
	 */
	public static function increment_reply_count( int $id, int $by = 1 ): void {
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET reply_count = GREATEST(reply_count + %d, 0), last_reply_at = %s, updated_at = %s WHERE id = %d',
				$by,
				$now,
				$now,
				$id
			)
		);
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
	}

	/**
	 * List published posts by a specific author.
	 *
	 * @param int $user_id Author user ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Pagination offset.
	 * @return object[]
	 */
	public static function list_by_author( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$table      = static::table();
		$spaces_tbl = \Jetonomy\table( 'spaces' );

		// Public method surfaced to other viewers (BP/FluentCommunity profile
		// tabs, etc.): gate by space visibility + per-post is_private so an
		// author's posts in private/hidden spaces (or their private posts in
		// public spaces) stay hidden from non-member / non-author viewers.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
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

		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.* FROM {$table} p
				 LEFT JOIN {$spaces_tbl} s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish'{$gate_sql}
				 ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				...array_merge( $gate_params, array( $limit, $offset ) )
			)
		);
		return $results ? $results : array();
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
				$order = 'p.vote_score DESC, p.id DESC';
				break;
			default:
				$order = 'hot_score DESC, p.id DESC';
				break;
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

		return array(
			'posts' => $results ? $results : array(),
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
	 * @param int $id Post ID.
	 * @return bool True if a draft was published, false if it was not a draft.
	 */
	public static function publish_draft( int $id ): bool {
		$post = static::find( $id );
		if ( ! $post || 'draft' !== ( $post->status ?? '' ) ) {
			return false;
		}

		// Clear published_at on the same write so the post stops rendering a
		// "scheduled" badge and can never be re-selected by get_due_scheduled()
		// (which keys on `published_at IS NOT NULL`). $wpdb->update() emits a
		// real SQL NULL for a null value.
		static::update(
			$id,
			array(
				'status'       => 'publish',
				'published_at' => null,
				'updated_at'   => now(),
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
	 * Moves all replies from the source post to the target, recalculates
	 * reply counts, and trashes the source post.
	 *
	 * @param int $source_id Source post ID (will be trashed).
	 * @param int $target_id Target post ID (receives replies).
	 * @return bool True on success.
	 */
	public static function merge_into( int $source_id, int $target_id ): bool {
		$source = static::find( $source_id );
		$target = static::find( $target_id );

		if ( ! $source || ! $target ) {
			return false;
		}

		$replies_table = \Jetonomy\table( 'replies' );

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

		// Recalculate reply count on target.
		$new_count = Reply::count_by_post( $target_id );
		static::update(
			$target_id,
			array(
				'reply_count' => $new_count,
				'updated_at'  => now(),
			)
		);

		// Trash the source post and decrement its space counter.
		static::update( $source_id, array( 'status' => 'trash' ) );
		Space::increment_post_count( (int) $source->space_id, -1 );

		do_action( 'jetonomy_post_merged', $source_id, $target_id );

		return true;
	}
}
