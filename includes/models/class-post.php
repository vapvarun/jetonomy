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
			}
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
				$order_by = 'vote_score DESC';
				break;

			case 'oldest':
				$order_by = 'created_at ASC';
				break;

			case 'newest':
				$order_by = 'created_at DESC';
				break;

			case 'unanswered':
				$order_by = 'created_at DESC';
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
				$order_by = 'vote_score DESC';
				break;

			case 'oldest':
				$order_by = 'created_at ASC';
				break;

			case 'newest':
				$order_by = 'created_at DESC';
				break;

			case 'unanswered':
				$order_by = 'created_at DESC';
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
		return $results ? $results : array();
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
	 * Toggle private visibility on a post.
	 *
	 * @param int  $id         Post ID.
	 * @param bool $is_private True to make private, false to make public.
	 * @return bool
	 */
	public static function set_private( int $id, bool $is_private = true ): bool {
		return static::update( $id, array( 'is_private' => $is_private ? 1 : 0 ) );
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
		$table   = static::table();
		$results = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE author_id = %d AND status = 'publish' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
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
	 * Roadmap statuses an idea (a post on a `type=ideas` space) can be in.
	 *
	 * Mirrors the `idea_status` enum in `class-schema.php`. Order is the
	 * canonical kanban progression — owners typically move ideas left to
	 * right (Submitted → Under Review → Planned → In Progress → Completed),
	 * with Declined as the off-ramp.
	 *
	 * @return string[]
	 */
	public static function valid_idea_statuses(): array {
		return array( 'submitted', 'under_review', 'planned', 'in_progress', 'completed', 'declined' );
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
	public static function set_idea_status( int $id, string $status ): bool {
		if ( ! in_array( $status, self::valid_idea_statuses(), true ) ) {
			return false;
		}
		return static::update( $id, array( 'idea_status' => $status ) );
	}

	/**
	 * Publish a scheduled post when its published_at time has arrived.
	 *
	 * @param int $id Post ID.
	 * @return bool True if published.
	 */
	public static function publish_scheduled( int $id ): bool {
		$post = static::find( $id );
		if ( ! $post || 'draft' !== ( $post->status ?? '' ) || empty( $post->published_at ) ) {
			return false;
		}

		static::update(
			$id,
			array(
				'status'     => 'publish',
				'updated_at' => now(),
			)
		);

		// Increment counters now that it's published.
		Space::increment_post_count( (int) $post->space_id );
		UserProfile::increment_post_count( (int) $post->author_id );

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
