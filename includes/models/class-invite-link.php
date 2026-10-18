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
	 * @return string The generated token, or '' when the row could not be saved.
	 */
	public static function generate( int $space_id, int $created_by, int $max_uses = 0, ?string $expires_at = null ): string {
		$token = wp_generate_password( 32, false );

		$id = self::insert(
			array(
				'space_id'   => $space_id,
				'token'      => $token,
				'created_by' => $created_by,
				'max_uses'   => $max_uses,
				'use_count'  => 0,
				'expires_at' => $expires_at,
				'created_at' => now(),
			)
		);

		return $id > 0 ? $token : '';
	}

	/**
	 * Turn the expiry an admin typed into the UTC value stored on the link.
	 *
	 * A date alone (the date picker's value) means the END of that day in the
	 * site timezone, so a link chosen to expire "on the 15th" still works on
	 * the 15th. Other values are read as site-local wall-clock time, per
	 * docs/standards/datetime-timezone.md.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string|null|\WP_Error UTC 'Y-m-d H:i:s', null for "never", or
	 *                               a 400 for an unreadable or past date.
	 */
	public static function expiry_utc( $raw ): string|null|\WP_Error {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return null;
		}
		$date_only = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw );
		try {
			$local = new \DateTimeImmutable( $date_only ? $raw . ' 23:59:59' : $raw, wp_timezone() );
		} catch ( \Exception $e ) {
			$local = null;
		}
		if ( ! $local || $local->getTimestamp() <= time() ) {
			return new \WP_Error( 'jetonomy_invalid_expiry', __( 'Choose an expiry date in the future.', 'jetonomy' ), array( 'status' => 400 ) );
		}
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * An invite's expiry as the site shows dates, in the site timezone ('' for
	 * none). The one formatter for the template, REST and admin lists.
	 */
	public static function expiry_label( object $invite ): string {
		return $invite->expires_at ? (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $invite->expires_at . ' UTC' ) ) : '';
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
		// expires_at is stored in UTC.
		if ( $invite->expires_at && strtotime( $invite->expires_at . ' UTC' ) < time() ) {
			return false;
		}
		if ( $invite->max_uses > 0 && $invite->use_count >= $invite->max_uses ) {
			return false;
		}
		return true;
	}

	/**
	 * Take one use of an invite, atomically. Two people accepting the last
	 * use at once cannot both get it: only the UPDATE that still finds room
	 * changes a row.
	 *
	 * @param int $id    Invite row ID.
	 * @param int $delta 1 to take a use, -1 to give one back.
	 * @return bool True when the use was taken (or given back).
	 */
	public static function use_invite( int $id, int $delta = 1 ): bool {
		$room = $delta > 0 ? ' AND (max_uses = 0 OR use_count < max_uses)' : ' AND use_count > 0';
		return 1 === (int) self::db()->query(
			self::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $room is one of two fixed fragments.
				'UPDATE ' . self::table() . " SET use_count = use_count + %d WHERE id = %d{$room}",
				$delta,
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
			array(
				'id'       => $id,
				'space_id' => $space_id,
			)
		);
	}

	/**
	 * One page of a space's invite links, newest first.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Page size.
	 * @param int $offset   Offset.
	 * @return object[]
	 */
	public static function list_by_space( int $space_id, int $limit = 100, int $offset = 0 ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE space_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$space_id,
				max( 1, $limit ),
				max( 0, $offset )
			)
		) ?: array();
	}

	/**
	 * How many invite links a space has.
	 */
	public static function count_by_space( int $space_id ): int {
		return (int) self::db()->get_var(
			self::db()->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE space_id = %d', $space_id )
		);
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
	 * @param bool   $commit  False runs every check without joining (the
	 *                        landing page shows a Join button; joining needs
	 *                        a POST, never a page view or link prefetch).
	 * @return array|\WP_Error On success: {status: joined|already_member|ready,
	 *         invite, space}. WP_Error codes: jetonomy_invalid_invite (404),
	 *         jetonomy_invite_expired (410), jetonomy_space_not_found (404),
	 *         jetonomy_login_required (401 — error data carries space_title /
	 *         space_description / space_slug so the landing page can render
	 *         the invite panel), or the SpaceMember::add() error.
	 */
	public static function accept( string $token, int $user_id, bool $commit = true ) {
		$invite = self::find_by_token( $token );
		if ( ! $invite ) {
			return new \WP_Error( 'jetonomy_invalid_invite', __( 'Invalid invite link.', 'jetonomy' ), array( 'status' => 404 ) );
		}

		if ( ! self::is_valid( $invite ) ) {
			return new \WP_Error( 'jetonomy_invite_expired', __( 'This invite link has expired or reached its usage limit.', 'jetonomy' ), array( 'status' => 410 ) );
		}

		$space = Space::find( (int) $invite->space_id );
		if ( ! $space ) {
			return new \WP_Error( 'jetonomy_space_not_found', sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'The %s for this invite no longer exists.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 404 ) );
		}

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'jetonomy_login_required',
				__( 'Please log in to use this invite.', 'jetonomy' ),
				array(
					// Minimal space context only — a valid token entitles the
					// holder to the invite panel, not the full space row.
					'status'            => 401,
					'space_title'       => (string) $space->title,
					'space_description' => (string) ( $space->description ?? '' ),
					'space_slug'        => (string) $space->slug,
				)
			);
		}

		// Before the membership check: a space ban leaves the member row in place, so
		// "already a member" would send a banned member into a space they cannot read.
		$restricted = \Jetonomy\Permissions\Permission_Engine::restriction_error( $user_id, 'join', (int) $space->id );
		if ( $restricted ) {
			return $restricted;
		}

		if ( SpaceMember::is_member( (int) $invite->space_id, $user_id ) ) {
			return array(
				'status' => 'already_member',
				'invite' => $invite,
				'space'  => $space,
			);
		}

		$blocked = SpaceMember::join_blocked( $space, $user_id );
		if ( $blocked ) {
			return $blocked;
		}

		if ( ! $commit ) {
			return array(
				'status' => 'ready',
				'invite' => $invite,
				'space'  => $space,
			);
		}

		if ( ! self::use_invite( (int) $invite->id ) ) {
			return new \WP_Error( 'jetonomy_invite_expired', __( 'This invite link has expired or reached its usage limit.', 'jetonomy' ), array( 'status' => 410 ) );
		}

		$add_result = SpaceMember::add( (int) $invite->space_id, $user_id, 'member', 'invite' );
		if ( is_wp_error( $add_result ) ) {
			self::use_invite( (int) $invite->id, -1 );
			return $add_result;
		}

		return array(
			'status' => 'joined',
			'invite' => $invite,
			'space'  => $space,
		);
	}
}
