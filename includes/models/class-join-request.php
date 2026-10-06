<?php
/**
 * Join request model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class JoinRequest extends Model {

	protected static function table_name(): string {
		return 'join_requests';
	}

	/**
	 * Create a new join request.
	 *
	 * @param int    $space_id
	 * @param int    $user_id
	 * @param string $message Optional message from the requester.
	 * @return int Inserted row ID.
	 */
	public static function create_request( int $space_id, int $user_id, string $message = '' ): int {
		return self::insert(
			[
				'space_id'   => $space_id,
				'user_id'    => $user_id,
				'message'    => $message,
				'status'     => 'pending',
				'created_at' => now(),
			]
		);
	}

	/**
	 * Find an existing pending request for a user/space combination.
	 *
	 * @param int $space_id
	 * @param int $user_id
	 * @return object|null
	 */
	public static function find_pending( int $space_id, int $user_id ): ?object {
		return self::db()->get_row(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . " WHERE space_id = %d AND user_id = %d AND status = 'pending'",
				$space_id,
				$user_id
			)
		);
	}

	/**
	 * List all pending requests for a space.
	 *
	 * @param int $space_id
	 * @return array
	 */
	public static function list_pending_for_space( int $space_id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . " WHERE space_id = %d AND status = 'pending' ORDER BY created_at DESC",
				$space_id
			)
		) ?: [];
	}

	/**
	 * Approve a join request.
	 *
	 * @param int $id          Request row ID.
	 * @param int $reviewed_by User ID of the reviewer.
	 * @return bool
	 */
	public static function approve( int $id, int $reviewed_by ): bool {
		return self::settle( $id, 'approved', $reviewed_by );
	}

	/**
	 * Deny a join request.
	 *
	 * @param int $id          Request row ID.
	 * @param int $reviewed_by User ID of the reviewer.
	 * @return bool
	 */
	public static function deny( int $id, int $reviewed_by ): bool {
		return self::settle( $id, 'denied', $reviewed_by );
	}

	/**
	 * Settle every pending request on a space that has stopped taking
	 * requests (Space::update() calls this when join_mode() leaves 'request').
	 *
	 * - Space now open: each requester is admitted and their request marked
	 *   approved - they asked to join, and anyone may now. A requester the
	 *   jetonomy_before_join_space veto refuses is marked denied instead,
	 *   since they could not join by the open door either.
	 * - Space now invite-only (or hidden): every request is denied - the
	 *   only way in is an invite, so the request can never be granted.
	 *
	 * Each requester is told through the existing approved/denied hooks
	 * (Notifier in-app + email, Pro webhooks), same as a manual decision.
	 *
	 * @param int  $space_id    Space whose queue to settle.
	 * @param bool $admit       True when the space is now open to join.
	 * @param int  $reviewed_by Who changed the policy (0 = system/CLI).
	 * @return int Number of requests settled.
	 */
	public static function resolve_pending_for_space( int $space_id, bool $admit, int $reviewed_by ): int {
		$user_ids = array_map(
			'intval',
			self::db()->get_col(
				self::db()->prepare(
					'SELECT user_id FROM ' . self::table() . " WHERE space_id = %d AND status = 'pending'",
					$space_id
				)
			)
		);
		if ( empty( $user_ids ) ) {
			return 0;
		}

		$outcome = array(
			'approved' => array(),
			'denied'   => array(),
		);
		foreach ( $user_ids as $user_id ) {
			// ponytail: one roster write + one notification per requester - the
			// same per-person cost as approving them one by one, paid once on a
			// policy save. Defer to a background job if queues reach thousands.
			$admitted = $admit && ! is_wp_error( SpaceMember::add( $space_id, $user_id, 'member' ) );

			$outcome[ $admitted ? 'approved' : 'denied' ][] = $user_id;
		}

		foreach ( $outcome as $status => $ids ) {
			if ( empty( $ids ) ) {
				continue;
			}
			self::clear_superseded( $space_id, $status, $ids, 0 );
			$in = implode( ',', $ids ); // intval()'d above.
			self::db()->query(
				self::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
					'UPDATE ' . self::table() . " SET status = %s, reviewed_by = %d, reviewed_at = %s WHERE space_id = %d AND status = 'pending' AND user_id IN ({$in})",
					$status,
					$reviewed_by,
					now(),
					$space_id
				)
			);
			foreach ( $ids as $user_id ) {
				self::fire( $status, $space_id, $user_id, $reviewed_by );
			}
		}

		return count( $user_ids );
	}

	/**
	 * Move one request to a terminal status and fire its hook.
	 *
	 * @param int    $id          Request row ID.
	 * @param string $status      'approved' | 'denied'.
	 * @param int    $reviewed_by Reviewer.
	 * @return bool
	 */
	private static function settle( int $id, string $status, int $reviewed_by ): bool {
		$request = self::find( $id );
		if ( ! $request ) {
			return false;
		}

		self::clear_superseded( (int) $request->space_id, $status, array( (int) $request->user_id ), $id );

		$ok = self::update(
			$id,
			[
				'status'      => $status,
				'reviewed_by' => $reviewed_by,
				'reviewed_at' => now(),
			]
		);
		if ( $ok ) {
			self::fire( $status, (int) $request->space_id, (int) $request->user_id, $reviewed_by );
		}
		return $ok;
	}

	/**
	 * Fire the outcome hook - Notifier tells the requester, Pro webhooks relay.
	 *
	 * @param string $status      'approved' | 'denied'.
	 * @param int    $space_id    Space ID.
	 * @param int    $user_id     Requester.
	 * @param int    $reviewed_by Reviewer.
	 */
	private static function fire( string $status, int $space_id, int $user_id, int $reviewed_by ): void {
		if ( 'approved' === $status ) {
			do_action( 'jetonomy_join_request_approved', $space_id, $user_id, $reviewed_by );
		} else {
			do_action( 'jetonomy_join_request_denied', $space_id, $user_id, $reviewed_by );
		}
	}

	/**
	 * Drop an older decision that would collide with the one being written.
	 *
	 * UNIQUE (space_id, user_id, status) allows one row per outcome, but a
	 * member can be denied, ask again, and be denied again (or approved,
	 * leave, and be approved again). The second decision's UPDATE hit a
	 * duplicate key and failed silently, so that request sat at 'pending'
	 * forever. The newer decision supersedes the older row.
	 *
	 * @param int    $space_id   Space ID.
	 * @param string $status     Status about to be written.
	 * @param int[]  $user_ids   Requesters (ints).
	 * @param int    $except_id  Row being settled (0 for none).
	 */
	private static function clear_superseded( int $space_id, string $status, array $user_ids, int $except_id ): void {
		$in = implode( ',', array_map( 'intval', $user_ids ) );
		self::db()->query(
			self::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of ints.
				'DELETE FROM ' . self::table() . " WHERE space_id = %d AND status = %s AND id <> %d AND user_id IN ({$in})",
				$space_id,
				$status,
				$except_id
			)
		);
	}
}
