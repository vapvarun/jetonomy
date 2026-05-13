<?php
/**
 * Space member model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use function Jetonomy\table;

class SpaceMember extends Model {

	protected static function table_name(): string {
		return 'space_members';
	}

	/**
	 * Add a user to a space (or update their role if already a member).
	 *
	 * Uses REPLACE INTO so re-adding an existing member updates the row.
	 * Increments the space's member_count after a successful insert.
	 *
	 * @param int    $space_id
	 * @param int    $user_id
	 * @param string $role
	 */
	public static function add( int $space_id, int $user_id, string $role = 'member' ): \WP_Error|bool {
		/**
		 * Filter whether a user should be allowed to join a space. Return WP_Error to abort.
		 *
		 * @param bool   $proceed  Whether to proceed (default true).
		 * @param int    $user_id  User ID.
		 * @param int    $space_id Space ID.
		 * @param string $role     Role being assigned.
		 */
		$proceed = apply_filters( 'jetonomy_before_join_space', true, $user_id, $space_id, $role );
		if ( is_wp_error( $proceed ) ) {
			return $proceed;
		}

		$exists = self::is_member( $space_id, $user_id );

		static::db()->query(
			static::db()->prepare(
				'REPLACE INTO ' . static::table() . ' (space_id, user_id, role, joined_at) VALUES (%d, %d, %s, %s)',
				$space_id,
				$user_id,
				$role,
				now()
			)
		);

		if ( ! $exists ) {
			Space::increment_member_count( $space_id );
			do_action( 'jetonomy_user_joined_space', $space_id, $user_id, $role );

			/**
			 * Alias of `jetonomy_user_joined_space` without the role arg —
			 * matches the Pro webhooks listener contract.
			 *
			 * @since 1.4.1
			 * @param int $space_id Space ID.
			 * @param int $user_id  Joined user ID.
			 */
			do_action( 'jetonomy_space_member_joined', $space_id, $user_id );
		}

		// 1.4.0 G1: every member-row write may change the privileged set
		// (admin / moderator role assignment OR a member promoted via add()
		// with role='admin'). Bust the cache unconditionally — the read path
		// rebuilds in 60s anyway, this just makes the refresh immediate when
		// the action is taken from wp-admin / REST.
		self::bust_privileged_cache( $space_id );

		return true;
	}

	/**
	 * Remove a user from a space and decrement the space's member_count.
	 *
	 * @param int $space_id
	 * @param int $user_id
	 */
	public static function remove( int $space_id, int $user_id ): void {
		$deleted = static::db()->delete(
			static::table(),
			[
				'space_id' => $space_id,
				'user_id'  => $user_id,
			]
		);

		if ( $deleted ) {
			Space::increment_member_count( $space_id, -1 );

			/**
			 * Fires when a user is removed from a space.
			 *
			 * @since 1.4.1
			 * @param int $space_id Space ID.
			 * @param int $user_id  Removed user ID.
			 */
			do_action( 'jetonomy_user_left_space', $space_id, $user_id );

			/**
			 * Alias matching the Pro webhooks listener contract.
			 *
			 * @since 1.4.1
			 * @param int $space_id Space ID.
			 * @param int $user_id  Removed user ID.
			 */
			do_action( 'jetonomy_space_member_left', $space_id, $user_id );
		}

		// G1 cache invalidation — see add() comment.
		self::bust_privileged_cache( $space_id );
	}

	/**
	 * Return the role of a user in a space, or null if they are not a member.
	 *
	 * @param int $space_id
	 * @param int $user_id
	 * @return string|null
	 */
	public static function get_role( int $space_id, int $user_id ): ?string {
		$value = static::db()->get_var(
			static::db()->prepare(
				'SELECT role FROM ' . static::table() . ' WHERE space_id = %d AND user_id = %d',
				$space_id,
				$user_id
			)
		);

		return null !== $value ? (string) $value : null;
	}

	/**
	 * Check whether a user is a member of a space.
	 *
	 * @param int $space_id
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_member( int $space_id, int $user_id ): bool {
		return null !== static::get_role( $space_id, $user_id );
	}

	/**
	 * List all members of a space ordered by role (DESC) then joined_at (ASC).
	 *
	 * @param int $space_id
	 * @param int $limit    Max rows to return. 0 = unbounded (default,
	 *                      preserves pre-1.4.3 behaviour for every caller
	 *                      that did not opt in to pagination).
	 * @param int $offset   Row offset. Ignored when $limit = 0.
	 * @return object[]
	 */
	public static function list_by_space( int $space_id, int $limit = 0, int $offset = 0 ): array {
		$base = 'SELECT * FROM ' . static::table() . ' WHERE space_id = %d ORDER BY role DESC, joined_at ASC';
		if ( $limit > 0 ) {
			return static::db()->get_results(
				static::db()->prepare( $base . ' LIMIT %d OFFSET %d', $space_id, $limit, max( 0, $offset ) )
			) ?: [];
		}
		return static::db()->get_results(
			static::db()->prepare( $base, $space_id )
		) ?: [];
	}

	/**
	 * Cheap COUNT(*) partner for {@see self::list_by_space()}. Used by
	 * paginated callers (the space members admin / template) without
	 * materialising every row.
	 *
	 * @param int $space_id
	 * @return int
	 */
	public static function count_by_space( int $space_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE space_id = %d',
				$space_id
			)
		);
	}

	/**
	 * List space admins + moderators, ordered admins-first then by join time.
	 *
	 * Powers the "Managed by" sidebar card (1.4.0 G1) so a visitor knows
	 * who runs a space without clicking around. Result is hydrated with
	 * `display_name` from `wp_users` and `avatar_url` so the template can
	 * render directly without a per-row WP_User lookup.
	 *
	 * Cached per space for 60s via transient `jt_priv_members_{id}`. Cache
	 * is busted automatically by `add()`, `remove()`, and role updates that
	 * call `static::bust_privileged_cache( $space_id )`.
	 *
	 * @param int $space_id
	 * @param int $limit Max rows (defaults to 20 — high enough for any real
	 *                   moderation team, low enough to keep the sidebar card
	 *                   visually scannable).
	 * @return array<int, object> List of objects with user_id, role, joined_at,
	 *                            display_name, avatar_url.
	 */
	public static function list_privileged( int $space_id, int $limit = 20 ): array {
		$cache_key = 'jt_priv_members_' . $space_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$members_table = static::table();
		$users_table   = $wpdb->users;

		// FIELD() puts admins before moderators deterministically (MySQL
		// otherwise sorts the role ENUM lexicographically: admin, moderator,
		// member, viewer — which happens to put admin first too, but only by
		// accident; we make it explicit to survive an ENUM reorder).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.user_id, m.role, m.joined_at, u.display_name
				FROM {$members_table} m
				INNER JOIN {$users_table} u ON u.ID = m.user_id
				WHERE m.space_id = %d AND m.role IN ('admin','moderator')
				ORDER BY FIELD(m.role,'admin','moderator'), m.joined_at ASC
				LIMIT %d",
				$space_id,
				$limit
			)
		) ?: [];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $row ) {
			$row->avatar_url = (string) get_avatar_url( (int) $row->user_id, [ 'size' => 48 ] );
		}

		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS );
		return $rows;
	}

	/**
	 * Bust the list_privileged transient for a space. Called from add(),
	 * remove(), and role-update paths.
	 */
	public static function bust_privileged_cache( int $space_id ): void {
		delete_transient( 'jt_priv_members_' . $space_id );
	}

	/**
	 * Per-request cache of role lookups, keyed `{space_id}|{user_id}`.
	 * Holds 'admin' / 'moderator' / null. Populated via warm_role_cache()
	 * (bulk loader) and read via role_label() (single lookup with lazy
	 * fallback). Lifetime is one PHP request — exactly the right scope
	 * for rendering a single page.
	 *
	 * @var array<string, ?string>
	 */
	private static array $role_label_cache = [];

	/**
	 * Bulk-fetch privileged roles for a set of users in a space.
	 *
	 * Powers the role-pill rendering on post / reply listings (1.4.0 G3).
	 * One indexed query, regardless of list length — caller passes the
	 * full set of author IDs visible on the page so the partial loop
	 * never issues a per-row query.
	 *
	 * Returns ONLY admins and moderators. Members and viewers are not in
	 * the result map (the pill template renders nothing for them, so
	 * including them would just bloat the cache).
	 *
	 * @param int   $space_id
	 * @param int[] $user_ids
	 * @return array<int,string>  user_id => 'admin'|'moderator'
	 */
	public static function roles_for_users( int $space_id, array $user_ids ): array {
		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		if ( $space_id <= 0 || empty( $user_ids ) ) {
			return [];
		}

		$db           = static::db();
		$table        = static::table();
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $db->get_results(
			$db->prepare(
				"SELECT user_id, role FROM {$table}
				WHERE space_id = %d
					AND user_id IN ({$placeholders})
					AND role IN ('admin','moderator')",
				array_merge( [ $space_id ], $user_ids )
			)
		) ?: [];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row->user_id ] = (string) $row->role;
		}
		return $out;
	}

	/**
	 * Pre-populate the per-request role cache for a list of users in a
	 * space. Call BEFORE rendering a list of posts / replies so each
	 * partial's role_label() lookup is O(1) — without this, a 200-reply
	 * page would issue 200 separate queries.
	 *
	 * Users absent from the bulk-loader result are seeded as null in
	 * the cache so subsequent role_label() calls still avoid the DB.
	 *
	 * @param int   $space_id
	 * @param int[] $user_ids
	 * @return void
	 */
	public static function warm_role_cache( int $space_id, array $user_ids ): void {
		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		if ( $space_id <= 0 || empty( $user_ids ) ) {
			return;
		}
		$found = self::roles_for_users( $space_id, $user_ids );
		foreach ( $user_ids as $uid ) {
			$key                            = $space_id . '|' . $uid;
			self::$role_label_cache[ $key ] = $found[ $uid ] ?? null;
		}
	}

	/**
	 * Return 'admin' / 'moderator' / null for a user in a space, hitting
	 * the warmed cache when available and falling back to a single
	 * indexed query when not. Same per-request lifetime as
	 * warm_role_cache.
	 *
	 * @param int $space_id
	 * @param int $user_id
	 * @return ?string
	 */
	public static function role_label( int $space_id, int $user_id ): ?string {
		$key = $space_id . '|' . $user_id;
		if ( ! array_key_exists( $key, self::$role_label_cache ) ) {
			$role                           = self::get_role( $space_id, $user_id );
			self::$role_label_cache[ $key ] = ( 'admin' === $role || 'moderator' === $role ) ? $role : null;
		}
		return self::$role_label_cache[ $key ];
	}

	/**
	 * Count how many users currently hold the 'admin' role for a space.
	 *
	 * Single COUNT(*) query against the existing `(space_id, role)` index.
	 * Used by the role-update guards (1.4.0 G4) to refuse demotion of the
	 * last admin so a space can never end up admin-less.
	 *
	 * @param int $space_id
	 * @return int
	 */
	public static function count_admins( int $space_id ): int {
		$db    = static::db();
		$table = static::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $db->get_var(
			$db->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE space_id = %d AND role = 'admin'",
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * List all spaces a user has joined.
	 *
	 * @param int $user_id
	 * @return object[]
	 */
	public static function list_user_spaces( int $user_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d ORDER BY joined_at ASC',
				$user_id
			)
		) ?: [];
	}

	/**
	 * Cheap COUNT(*) partner for {@see self::list_user_spaces()}. Mirrors
	 * its scoping (every space row the user holds any role in) so callers
	 * needing only the number — `/users/me` `spaces_joined_count`, profile
	 * cards — never have to materialise the full row set just to PHP-count
	 * it. Backed by the existing `user_joined (user_id, joined_at)` index.
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function count_user_spaces( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE user_id = %d',
				$user_id
			)
		);
	}

	/**
	 * Return the space IDs where the user holds a privileged role (moderator or admin).
	 *
	 * Used by the moderation queue scope: a space mod only sees flags in spaces
	 * they moderate. Single indexed query.
	 *
	 * @param int $user_id
	 * @return int[]
	 */
	public static function moderated_space_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$rows = static::db()->get_col(
			static::db()->prepare(
				'SELECT space_id FROM ' . static::table() . " WHERE user_id = %d AND role IN ('moderator','admin')",
				$user_id
			)
		);

		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * Return space ids the user is part of, optionally filtered by role.
	 *
	 * Used by the My Spaces landing (G7) to render two stacked sections —
	 * "Spaces I run" (admin / moderator) above "Spaces I'm in" (member only).
	 * One indexed query per call; the caller hydrates Space rows from the ids.
	 *
	 * @param int         $user_id
	 * @param string|null $role_filter Optional. 'admin', 'moderator', 'member',
	 *                                 or 'privileged' (admin OR moderator).
	 *                                 null returns every space the user is in.
	 * @return int[]
	 */
	public static function spaces_for_user( int $user_id, ?string $role_filter = null ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$where = 'user_id = %d';
		$args  = array( $user_id );
		if ( 'privileged' === $role_filter ) {
			$where .= " AND role IN ('admin','moderator')";
		} elseif ( in_array( $role_filter, array( 'admin', 'moderator', 'member' ), true ) ) {
			$where .= ' AND role = %s';
			$args[] = $role_filter;
		}

		$rows = static::db()->get_col(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT space_id FROM ' . static::table() . ' WHERE ' . $where . ' ORDER BY joined_at DESC',
				...$args
			)
		);
		return array_map( 'intval', $rows ?: array() );
	}

	/**
	 * Does the user hold a privileged role (moderator or admin) in at least one space?
	 *
	 * Cheap existence check used by the main nav to decide whether to render
	 * a Moderation link for a non-cap user.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function has_privileged_membership( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) static::db()->get_var(
			static::db()->prepare(
				'SELECT 1 FROM ' . static::table() . " WHERE user_id = %d AND role IN ('moderator','admin') LIMIT 1",
				$user_id
			)
		);
	}
}
