<?php
/**
 * Restriction model.
 *
 * @package Jetonomy
 */

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
	 * Check whether an IP address is currently banned.
	 *
	 * IP bans are stored with type = 'ip_ban' and the IP in the reason column.
	 *
	 * @param string $ip IP address to check.
	 * @return bool
	 */
	public static function is_ip_banned( string $ip ): bool {
		$now = now();
		return (bool) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . "
				WHERE type = 'ip_ban'
				  AND reason = %s
				  AND (expires_at IS NULL OR expires_at > %s)",
				$ip,
				$now
			)
		);
	}

	/**
	 * List active (non-expired) member restrictions, newest first.
	 *
	 * Powers the moderator ban-management surface (REST GET /moderation/ban).
	 * Defaults to the member-facing types (global_ban, space_ban, silence);
	 * ip_ban / post_restrict are excluded unless an explicit `type` filter asks
	 * for one, since they are not tied to a listable member. Paginated for
	 * big-site safety — served by the user_type_space + expires indexes.
	 *
	 * @param array $args {type?, user_id?, space_id?, limit?, offset?}
	 * @return object[] Raw restriction rows (id, user_id, type, space_id, reason,
	 *                  issued_by, expires_at, created_at).
	 */
	public static function list_active( array $args = array() ): array {
		list( $where, $params ) = self::active_filters( $args );

		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = 'SELECT id, user_id, type, space_id, reason, issued_by, expires_at, created_at
			FROM ' . static::table() . '
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY created_at DESC, id DESC
			LIMIT %d OFFSET %d';

		$params[] = $limit;
		$params[] = $offset;

		return static::db()->get_results( static::db()->prepare( $sql, $params ) ) ?: array();
	}

	/**
	 * Count active (non-expired) member restrictions matching the same filters
	 * as {@see list_active()}. Used for the has_more pagination cursor.
	 *
	 * @param array $args {type?, user_id?, space_id?}
	 * @return int
	 */
	public static function count_active( array $args = array() ): int {
		list( $where, $params ) = self::active_filters( $args );

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where ),
				$params
			)
		);
	}

	/**
	 * Shared WHERE builder for the active-restriction list + count so the two
	 * never drift (a mismatched filter makes has_more lie).
	 *
	 * @param array $args {type?, user_id?, space_id?}
	 * @return array{0: string[], 1: array} [where clauses, prepare params]
	 */
	private static function active_filters( array $args ): array {
		$where  = array( '(expires_at IS NULL OR expires_at > %s)' );
		$params = array( now() );

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = (string) $args['type'];
		} else {
			// Member-facing bans only; ip_ban / post_restrict have no listable member.
			$where[] = "type IN ('global_ban','space_ban','silence')";
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( isset( $args['space_id'] ) && null !== $args['space_id'] ) {
			$where[]  = 'space_id = %d';
			$params[] = (int) $args['space_id'];
		}

		return array( $where, $params );
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
