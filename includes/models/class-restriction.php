<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Restriction extends Model {

	protected static function table_name(): string {
		return 'restrictions';
	}

	/**
	 * Issue a ban or silence restriction.
	 *
	 * @param int         $user_id    Target user.
	 * @param string      $type       Restriction type (e.g. 'global_ban', 'space_ban', 'silence').
	 * @param int         $issued_by  ID of the moderator/admin issuing the restriction.
	 * @param int|null    $space_id   Scoped space ID, or null for global restrictions.
	 * @param string|null $reason     Optional reason text.
	 * @param string|null $expires_at Optional expiry datetime (MySQL format). Null = permanent.
	 * @return int Inserted row ID.
	 */
	public static function ban(
		int $user_id,
		string $type,
		int $issued_by,
		?int $space_id = null,
		?string $reason = null,
		?string $expires_at = null
	): int {
		$data = [
			'user_id'    => $user_id,
			'type'       => $type,
			'issued_by'  => $issued_by,
			'created_at' => now(),
		];

		if ( null !== $space_id ) {
			$data['space_id'] = $space_id;
		}
		if ( null !== $reason ) {
			$data['reason'] = $reason;
		}
		if ( null !== $expires_at ) {
			$data['expires_at'] = $expires_at;
		}

		return static::insert( $data );
	}

	/**
	 * Check whether a user is globally banned (active, non-expired).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_banned( int $user_id ): bool {
		$now = now();
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . '
				WHERE user_id = %d
				  AND type = %s
				  AND (expires_at IS NULL OR expires_at > %s)
				LIMIT 1',
				$user_id,
				'global_ban',
				$now
			)
		);

		return null !== $row;
	}

	/**
	 * Check whether a user is banned from a specific space (active, non-expired).
	 *
	 * @param int $user_id
	 * @param int $space_id
	 * @return bool
	 */
	public static function is_space_banned( int $user_id, int $space_id ): bool {
		$now = now();
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . '
				WHERE user_id = %d
				  AND type = %s
				  AND space_id = %d
				  AND (expires_at IS NULL OR expires_at > %s)
				LIMIT 1',
				$user_id,
				'space_ban',
				$space_id,
				$now
			)
		);

		return null !== $row;
	}

	/**
	 * Check whether a user is currently silenced (active, non-expired).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_silenced( int $user_id ): bool {
		$now = now();
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . '
				WHERE user_id = %d
				  AND type = %s
				  AND (expires_at IS NULL OR expires_at > %s)
				LIMIT 1',
				$user_id,
				'silence',
				$now
			)
		);

		return null !== $row;
	}

	/**
	 * Lift a restriction by its ID.
	 *
	 * @param int $id Restriction row ID.
	 * @return bool True if a row was deleted.
	 */
	public static function remove_ban( int $id ): bool {
		return static::delete( $id );
	}
}
