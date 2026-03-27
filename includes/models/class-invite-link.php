<?php
/**
 * Invite link model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class InviteLink extends Model {

	protected static function table_name(): string {
		return 'invite_links';
	}

	/**
	 * Generate a new invite link token for a space.
	 *
	 * @param int         $space_id   The space to invite into.
	 * @param int         $created_by The user creating the invite.
	 * @param int         $max_uses   Maximum uses (0 = unlimited).
	 * @param string|null $expires_at Optional expiry datetime (MySQL format).
	 * @return string The generated token.
	 */
	public static function generate( int $space_id, int $created_by, int $max_uses = 0, ?string $expires_at = null ): string {
		$token = wp_generate_password( 32, false );

		self::insert(
			[
				'space_id'   => $space_id,
				'token'      => $token,
				'created_by' => $created_by,
				'max_uses'   => $max_uses,
				'use_count'  => 0,
				'expires_at' => $expires_at,
				'created_at' => now(),
			]
		);

		return $token;
	}

	/**
	 * Find an invite link by its token.
	 */
	public static function find_by_token( string $token ): ?object {
		$row = self::db()->get_row(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE token = %s',
				$token
			)
		);

		return $row ?: null;
	}

	/**
	 * Check whether an invite is still valid (not expired, not maxed out).
	 */
	public static function is_valid( object $invite ): bool {
		if ( $invite->expires_at && strtotime( $invite->expires_at ) < time() ) {
			return false;
		}
		if ( $invite->max_uses > 0 && $invite->use_count >= $invite->max_uses ) {
			return false;
		}
		return true;
	}

	/**
	 * Increment the use count for an invite.
	 */
	public static function use_invite( int $id ): void {
		self::db()->query(
			self::db()->prepare(
				'UPDATE ' . self::table() . ' SET use_count = use_count + 1 WHERE id = %d',
				$id
			)
		);
	}

	/**
	 * List all invite links for a space.
	 */
	public static function list_by_space( int $space_id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE space_id = %d ORDER BY created_at DESC',
				$space_id
			)
		) ?: [];
	}
}
