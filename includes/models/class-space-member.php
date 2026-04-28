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
	 * @return object[]
	 */
	public static function list_by_space( int $space_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE space_id = %d ORDER BY role DESC, joined_at ASC',
				$space_id
			)
		) ?: [];
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
