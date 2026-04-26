<?php
/**
 * Space model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use Jetonomy\Cache;

class Space extends Model {

	protected static function table_name(): string {
		return 'spaces';
	}

	/**
	 * Find a space by ID, with 5-minute object-cache.
	 *
	 * @param int $id Space ID.
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Cache::remember(
			"space:{$id}",
			fn() => parent::find( $id ),
			300
		);
	}

	/**
	 * Invalidate the cached row and then delegate to Model::update().
	 *
	 * @param int   $id   Space ID.
	 * @param array $data Column data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		Cache::delete( "space:{$id}" );
		return parent::update( $id, $data );
	}

	/**
	 * Create a new space.
	 *
	 * Sets created_at and updated_at if absent, increments the parent
	 * category's space_count after a successful insert, and seeds the
	 * creator as a space admin so the new owner can run their space
	 * from the moment it exists.
	 *
	 * The seeding step is the behaviour change in 1.4.0. Prior to this,
	 * only the Abilities flow seeded the creator; REST POST /spaces and
	 * the wp-admin AJAX path created spaces with no admin row, leaving
	 * legitimate owners locked out of role management until they were
	 * added by another admin.
	 *
	 * Callers that intentionally create spaces on behalf of someone
	 * else (importers, BuddyPress group sync, demo seeders running
	 * unattended) can pass an explicit user ID, or pass 0 to skip
	 * seeding when the seed cannot be determined.
	 *
	 * @param array    $data            Column data.
	 * @param int|null $creator_user_id Optional. User ID to seed as
	 *                                  space admin. Defaults to the
	 *                                  current logged-in user. Pass 0
	 *                                  to skip seeding entirely.
	 * @return int Inserted row ID.
	 */
	public static function create( array $data, ?int $creator_user_id = null ): int {
		$now  = now();
		$data = array_merge(
			[
				'created_at' => $now,
				'updated_at' => $now,
			],
			$data
		);

		$id = static::insert( $data );

		if ( $id <= 0 ) {
			return 0;
		}

		if ( ! empty( $data['category_id'] ) ) {
			Category::increment_space_count( (int) $data['category_id'] );
		}

		$seed_user_id = $creator_user_id ?? get_current_user_id();
		if ( $seed_user_id > 0 ) {
			SpaceMember::add( $id, $seed_user_id, 'admin' );
		}

		return $id;
	}

	/**
	 * Find a space by its slug, with 5-minute object-cache.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ): ?object {
		return Cache::remember(
			"space:slug:{$slug}",
			function () use ( $slug ) {
				$row = static::db()->get_row(
					static::db()->prepare(
						'SELECT * FROM ' . static::table() . ' WHERE slug = %s',
						$slug
					)
				);
				return $row ?: null;
			},
			300
		);
	}

	/**
	 * List top-level spaces in a category.
	 *
	 * @param int $category_id
	 * @return object[]
	 */
	public static function list_by_category( int $category_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE category_id = %d AND (parent_id IS NULL OR parent_id = 0) AND (visibility IS NULL OR visibility != 'hidden') ORDER BY sort_order ASC, title ASC",
				$category_id
			)
		) ?: [];
	}

	/**
	 * List spaces with no category assigned (category_id IS NULL or 0), excluding hidden.
	 *
	 * @return object[]
	 */
	public static function list_uncategorized(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE (category_id IS NULL OR category_id = 0) AND (parent_id IS NULL OR parent_id = 0) AND (visibility IS NULL OR visibility != 'hidden') ORDER BY sort_order ASC, title ASC"
		) ?: [];
	}

	/**
	 * List child spaces for a given parent space.
	 *
	 * @param int $parent_id
	 * @return object[]
	 */
	public static function list_children( int $parent_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, title ASC',
				$parent_id
			)
		) ?: [];
	}

	/**
	 * Increment the post_count and update activity timestamps.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_post_count( int $id, int $by = 1 ): void {
		Cache::delete( "space:{$id}" );
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET post_count = GREATEST(post_count + %d, 0), last_activity_at = %s, updated_at = %s WHERE id = %d',
				$by,
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * Increment the member_count and update updated_at.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_member_count( int $id, int $by = 1 ): void {
		Cache::delete( "space:{$id}" );
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET member_count = GREATEST(member_count + %d, 0), updated_at = %s WHERE id = %d',
				$by,
				$now,
				$id
			)
		);
	}

	/**
	 * List all spaces filtered by status.
	 *
	 * @param string $status Row status value to filter by (e.g. 'active', 'archived').
	 * @return object[]
	 */
	public static function list_all( string $status = 'active' ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE status = %s ORDER BY title ASC',
				$status
			)
		) ?: [];
	}

	/**
	 * List spaces visible to a given user, with pagination.
	 *
	 * Visibility rules (all resolved in SQL — no PHP-side filtering):
	 * - Logged-out users: only public spaces.
	 * - Logged-in non-admins: public spaces + private/hidden spaces where the user
	 *   is a member (LEFT JOIN on space_members).
	 * - WP admins (manage_options): all spaces regardless of visibility.
	 *
	 * @param int         $user_id     Current user ID (0 for guests).
	 * @param int|null    $category_id Optional category filter.
	 * @param string|null $type        Optional type filter (e.g. 'forum', 'qa').
	 * @param string|null $visibility  Optional explicit visibility filter.
	 * @param int         $per_page    Items per page.
	 * @param int         $offset      SQL OFFSET.
	 * @param string      $order_by    ORDER BY clause (pre-sanitised).
	 * @return array{spaces: object[], total: int}
	 */
	public static function list_visible(
		int $user_id,
		?int $category_id = null,
		?string $type = null,
		?string $visibility = null,
		int $per_page = 20,
		int $offset = 0,
		string $order_by = 'sort_order ASC, title ASC'
	): array {
		$db            = static::db();
		$spaces_table  = static::table();
		$members_table = \Jetonomy\table( 'space_members' );
		$is_admin      = $user_id && user_can( $user_id, 'manage_options' );

		$where  = [];
		$values = [];

		// Category filter.
		if ( $category_id ) {
			$where[]  = 's.category_id = %d';
			$values[] = $category_id;
		}

		// Type filter.
		if ( $type ) {
			$where[]  = 's.type = %s';
			$values[] = $type;
		}

		// Explicit visibility filter.
		if ( $visibility ) {
			$where[]  = 's.visibility = %s';
			$values[] = $visibility;
		}

		// Visibility rules — only when no explicit visibility filter is set.
		if ( ! $visibility && ! $is_admin ) {
			if ( ! $user_id ) {
				// Guests see only public spaces.
				$where[] = "s.visibility = 'public'";
			} else {
				// Logged-in non-admin: public OR member of the space.
				$where[] = "(s.visibility = 'public' OR sm.user_id IS NOT NULL)";
			}
		}

		/**
		 * Filter space query parameters before execution.
		 *
		 * @param array    $args    Query parameters: where (clauses), values, order_by, per_page, offset.
		 * @param int      $user_id Current user ID (0 for guests).
		 */
		$args = apply_filters(
			'jetonomy_spaces_query_args',
			array(
				'where'    => $where,
				'values'   => $values,
				'order_by' => $order_by,
				'per_page' => $per_page,
				'offset'   => $offset,
			),
			$user_id
		);

		$where    = $args['where'];
		$values   = $args['values'];
		$order_by = $args['order_by'];
		$per_page = (int) $args['per_page'];
		$offset   = (int) $args['offset'];

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Use LEFT JOIN for logged-in non-admins (even when not needed for admins/guests,
		// the JOIN is harmless and keeps the query simple). We use DISTINCT because
		// the join is 1:1 (unique on space_id + user_id), but DISTINCT guards against
		// unexpected duplicates.
		$join_sql = '';
		if ( $user_id && ! $is_admin ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$join_sql = $db->prepare(
				"LEFT JOIN {$members_table} sm ON sm.space_id = s.id AND sm.user_id = %d",
				$user_id
			);
		}

		// Count query.
		$count_sql = "SELECT COUNT(DISTINCT s.id) FROM {$spaces_table} s {$join_sql} {$where_sql}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $db->get_var( $db->prepare( $count_sql, ...$values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $db->get_var( $count_sql );
		}

		// Data query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data_sql = "SELECT DISTINCT s.* FROM {$spaces_table} s {$join_sql} {$where_sql} ORDER BY s.{$order_by} LIMIT %d OFFSET %d";

		$all_values   = $values;
		$all_values[] = $per_page;
		$all_values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$spaces = $db->get_results( $db->prepare( $data_sql, ...$all_values ) ) ?: [];

		return [
			'spaces' => $spaces,
			'total'  => $total,
		];
	}

	/**
	 * Return the decoded settings array for a space.
	 *
	 * @param int $id Space ID.
	 * @return array Settings key/value pairs, or empty array if none.
	 */
	public static function get_settings( int $id ): array {
		$row = static::find( $id );
		if ( ! $row || empty( $row->settings ) ) {
			return [];
		}

		$decoded = json_decode( $row->settings, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Resolve posts_per_page for a space: space setting → global setting → 20.
	 *
	 * @param int $space_id Space ID.
	 * @return int Resolved posts per page.
	 */
	public static function get_posts_per_page( int $space_id ): int {
		$space_settings = self::get_settings( $space_id );
		if ( ! empty( $space_settings['posts_per_page'] ) ) {
			return (int) $space_settings['posts_per_page'];
		}

		$global = get_option( 'jetonomy_settings', [] );
		return (int) ( $global['posts_per_page'] ?? 20 );
	}
}
