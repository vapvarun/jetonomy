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
	public static function add( int $space_id, int $user_id, string $role = 'member' ): \WP_Error|true {
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
}
