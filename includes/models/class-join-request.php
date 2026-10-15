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
			array(
				'space_id'   => $space_id,
				'user_id'    => $user_id,
				'message'    => $message,
				'status'     => 'pending',
				'created_at' => now(),
			)
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
	 * One page of a space's pending requests, newest first (uses the
	 * space_status_created index).
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Page size.
	 * @param int $offset   Offset.
	 * @return object[]
	 */
	public static function list_pending_for_space( int $space_id, int $limit = 100, int $offset = 0 ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT * FROM ' . self::table() . " WHERE space_id = %d AND status = 'pending' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$space_id,
				max( 1, $limit ),
				max( 0, $offset )
			)
		) ?: array();
	}

	/**
	 * How many requests are pending on a space.
	 */
	public static function count_pending_for_space( int $space_id ): int {
		return (int) self::db()->get_var(
			self::db()->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE space_id = %d AND status = 'pending'", $space_id )
		);
	}

	/**
	 * Approve a pending join request: admit the requester, then settle it.
	 *
	 * Admission comes first. If a join filter vetoes it, the request stays
	 * pending and nobody is told "approved" for a space they are not in.
	 *
	 * @param int $id          Request row ID.
	 * @param int $reviewed_by User ID of the reviewer.
	 * @return true|\WP_Error WP_Error when the request is gone or already
	 *                        decided (409) or the admission was refused.
	 */
	public static function approve( int $id, int $reviewed_by ): bool|\WP_Error {
		$request = self::find( $id );
		if ( ! $request || 'pending' !== (string) $request->status ) {
			return self::already_handled();
		}

		// Banned from the space after asking: approving must not let them in.
		if ( Restriction::is_space_banned( (int) $request->user_id, (int) $request->space_id ) ) {
			return new \WP_Error( 'jetonomy_space_banned', sprintf( /* translators: 1: singular member label, lowercase; 2: singular space label, lowercase. */ __( 'This %1$s is banned from this %2$s. Deny the request or lift the ban first.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', false, true ), \Jetonomy\space_label( false, true ) ), array( 'status' => 403 ) );
		}

		$added = SpaceMember::add( (int) $request->space_id, (int) $request->user_id, 'member' );
		if ( is_wp_error( $added ) ) {
			return $added;
		}

		return self::settle( $id, 'approved', $reviewed_by ) ? true : self::already_handled();
	}

	/**
	 * A member takes back their own pending request. Only a still-pending row
	 * goes, and the "new join request" notices sent to the space's staff go
	 * with it, so nobody acts on a request that no longer exists.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  The requesting member.
	 * @return bool True when a pending request was withdrawn.
	 */
	public static function withdraw( int $space_id, int $user_id ): bool {
		$removed = (int) self::db()->query(
			self::db()->prepare(
				'DELETE FROM ' . self::table() . " WHERE space_id = %d AND user_id = %d AND status = 'pending'",
				$space_id,
				$user_id
			)
		);
		if ( 1 > $removed ) {
			return false;
		}
		Notification::delete_caused_by( 'join_request', 'space', $space_id, $user_id );
		/**
		 * Fires after a member withdraws their pending join request.
		 *
		 * @since 2.0.2
		 *
		 * @param int $space_id Space ID.
		 * @param int $user_id  The member.
		 */
		do_action( 'jetonomy_join_request_withdrawn', $space_id, $user_id );
		return true;
	}

	/**
	 * The answer when a request was decided by someone else first.
	 */
	private static function already_handled(): \WP_Error {
		return new \WP_Error( 'jetonomy_join_request_processed', __( 'This request has already been handled.', 'jetonomy' ), array( 'status' => 409 ) );
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
		$rows = self::db()->get_results(
			self::db()->prepare(
				'SELECT id, user_id FROM ' . self::table() . " WHERE space_id = %d AND status = 'pending'",
				$space_id
			)
		) ?: array();

		$settled = 0;
		foreach ( $rows as $row ) {
			// ponytail: one roster write + one notification per requester - the
			// same per-person cost as approving them one by one, paid once on a
			// policy save. Defer to a background job if queues reach thousands.
			$user_id = (int) $row->user_id;
			// Someone already in the space (granted a role meanwhile) keeps it:
			// add() would lift a viewer to member.
			// A member banned from the space meanwhile is denied, not admitted.
			$admitted = $admit && ! Restriction::is_space_banned( $user_id, $space_id )
				&& ( null !== SpaceMember::get_role( $space_id, $user_id )
					|| ! is_wp_error( SpaceMember::add( $space_id, $user_id, 'member' ) ) );

			// settle() moves only a still-pending row and fires once for it.
			$settled += (int) self::settle( (int) $row->id, $admitted ? 'approved' : 'denied', $reviewed_by );
		}

		return $settled;
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
		if ( ! $request || 'pending' !== (string) $request->status ) {
			return false;
		}

		self::clear_superseded( (int) $request->space_id, $status, array( (int) $request->user_id ), $id );

		// Only a still-pending row moves, and only the call that moved it
		// fires the hook: a concurrent approve and deny cannot both notify.
		$changed = self::db()->query(
			self::db()->prepare(
				'UPDATE ' . self::table() . " SET status = %s, reviewed_by = %d, reviewed_at = %s WHERE id = %d AND status = 'pending'",
				$status,
				$reviewed_by,
				now(),
				$id
			)
		);
		if ( 1 !== (int) $changed ) {
			return false;
		}
		self::fire( $status, (int) $request->space_id, (int) $request->user_id, $reviewed_by );
		return true;
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
			/**
			 * Fires after a join request is approved.
			 *
			 * @since 1.4.4
			 *
			 * @param int $space_id    Space ID.
			 * @param int $user_id     Requesting user ID.
			 * @param int $reviewed_by Reviewer user ID.
			 */
			do_action( 'jetonomy_join_request_approved', $space_id, $user_id, $reviewed_by );
		} else {
			/**
			 * Fires after a join request is denied.
			 *
			 * @since 1.4.4
			 *
			 * @param int $space_id    Space ID.
			 * @param int $user_id     Requesting user ID.
			 * @param int $reviewed_by Reviewer user ID.
			 */
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
