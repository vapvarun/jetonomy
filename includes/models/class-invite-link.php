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
	 * Revoke (delete) an invite link, scoped to BOTH its id and the owning
	 * space so one space's admin can never revoke another space's link.
	 *
	 * @param int $id       Invite link row ID.
	 * @param int $space_id The space the link must belong to.
	 * @return bool True when a matching row was deleted.
	 */
	public static function revoke( int $id, int $space_id ): bool {
		return (bool) self::db()->delete(
			self::table(),
			[
				'id'       => $id,
				'space_id' => $space_id,
			]
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

	/**
	 * Accept an invite token for a user — the single source of truth for
	 * the token → membership flow. The invite landing template and
	 * POST /invite/{token} both call this; until 1.5.0 each forked its own
	 * copy of the validation + join sequence, a drift risk on a
	 * security-sensitive path (audit B).
	 *
	 * @param string $token   Invite token.
	 * @param int    $user_id Accepting user ID (0 = not logged in).
	 * @return array|\WP_Error On success: {status: joined|already_member,
	 *         invite, space}. WP_Error codes: jetonomy_invalid_invite (404),
	 *         jetonomy_invite_expired (410), jetonomy_space_not_found (404),
	 *         jetonomy_login_required (401 — error data carries space_title /
	 *         space_description / space_slug so the landing page can render
	 *         the invite panel), or the SpaceMember::add() error.
	 */
	public static function accept( string $token, int $user_id ) {
		$invite = self::find_by_token( $token );
		if ( ! $invite ) {
			return new \WP_Error( 'jetonomy_invalid_invite', __( 'Invalid invite link.', 'jetonomy' ), [ 'status' => 404 ] );
		}

		if ( ! self::is_valid( $invite ) ) {
			return new \WP_Error( 'jetonomy_invite_expired', __( 'This invite link has expired or reached its usage limit.', 'jetonomy' ), [ 'status' => 410 ] );
		}

		$space = Space::find( (int) $invite->space_id );
		if ( ! $space ) {
			return new \WP_Error( 'jetonomy_space_not_found', __( 'The space for this invite no longer exists.', 'jetonomy' ), [ 'status' => 404 ] );
		}

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'jetonomy_login_required',
				__( 'Please log in to use this invite.', 'jetonomy' ),
				[
					// Minimal space context only — a valid token entitles the
					// holder to the invite panel, not the full space row.
					'status'            => 401,
					'space_title'       => (string) $space->title,
					'space_description' => (string) ( $space->description ?? '' ),
					'space_slug'        => (string) $space->slug,
				]
			);
		}

		if ( SpaceMember::is_member( (int) $invite->space_id, $user_id ) ) {
			return [
				'status' => 'already_member',
				'invite' => $invite,
				'space'  => $space,
			];
		}

		$add_result = SpaceMember::add( (int) $invite->space_id, $user_id, 'member' );
		if ( is_wp_error( $add_result ) ) {
			return $add_result;
		}

		self::use_invite( (int) $invite->id );

		return [
			'status' => 'joined',
			'invite' => $invite,
			'space'  => $space,
		];
	}
}
