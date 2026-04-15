<?php
/**
 * Member journey — space membership, roles, and join requests.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\SpaceMember;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering every space-membership action a user or moderator
 * can perform: join, leave, role changes, membership lookups, member listings,
 * and the approval-gated join request lifecycle.
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method takes
 * plain primitives, delegates to the underlying model class, and returns a
 * {@see Journey_Result}. Commands format the result for the terminal; PHPUnit
 * tests read the same fields and assert on them.
 *
 * All permission checks are delegated upstream to Permission_Engine via the
 * model filters (`jetonomy_before_join_space`, etc.), so this class can be
 * called in both authenticated and impersonated contexts without duplicating
 * capability logic.
 */
final class Member_Journey {

	private const ALLOWED_ROLES = [ 'member', 'moderator', 'admin' ];

	/**
	 * Add a user to a space with the given role (default `member`).
	 *
	 * @param int    $space_id Space row ID.
	 * @param int    $user_id  User ID to add.
	 * @param string $role     Role to assign; must be in {@see ALLOWED_ROLES}.
	 */
	public function join( int $space_id, int $user_id, string $role = 'member' ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return Journey_Result::fail( 'space_id and user_id must both be positive.' );
		}
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			return Journey_Result::fail( 'role must be one of: ' . implode( ', ', self::ALLOWED_ROLES ) . '.' );
		}

		$result = SpaceMember::add( $space_id, $user_id, $role );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( true !== $result ) {
			return Journey_Result::fail( sprintf( 'SpaceMember::add(%d, %d) returned false.', $space_id, $user_id ) );
		}

		return Journey_Result::ok(
			[
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'role'     => $role,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Remove a user from a space.
	 *
	 * @param int $space_id Space row ID.
	 * @param int $user_id  User ID to remove.
	 */
	public function leave( int $space_id, int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return Journey_Result::fail( 'space_id and user_id must both be positive.' );
		}

		SpaceMember::remove( $space_id, $user_id );

		return Journey_Result::ok(
			[
				'space_id' => $space_id,
				'user_id'  => $user_id,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Upsert a user's role in a space. Wraps SpaceMember::add which uses
	 * REPLACE INTO so calling this on an existing member overwrites the role.
	 *
	 * @param int    $space_id Space row ID.
	 * @param int    $user_id  User ID whose role should change.
	 * @param string $role     New role; must be in {@see ALLOWED_ROLES}.
	 */
	public function set_role( int $space_id, int $user_id, string $role ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return Journey_Result::fail( 'space_id and user_id must both be positive.' );
		}
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			return Journey_Result::fail( 'role must be one of: member, moderator, admin.' );
		}

		$result = SpaceMember::add( $space_id, $user_id, $role );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( true !== $result ) {
			return Journey_Result::fail( sprintf( 'SpaceMember::add(%d, %d) returned false.', $space_id, $user_id ) );
		}

		return Journey_Result::ok(
			[
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'role'     => $role,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Check whether a user is a member of a space.
	 *
	 * @param int $space_id Space row ID.
	 * @param int $user_id  User ID to check.
	 */
	public function is_member( int $space_id, int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return Journey_Result::fail( 'space_id and user_id must both be positive.' );
		}

		$is_member = SpaceMember::is_member( $space_id, $user_id );

		return Journey_Result::ok(
			[
				'space_id'  => $space_id,
				'user_id'   => $user_id,
				'is_member' => $is_member,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Return the user's role in a space, or null if they are not a member.
	 *
	 * @param int $space_id Space row ID.
	 * @param int $user_id  User ID to look up.
	 */
	public function get_role( int $space_id, int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return Journey_Result::fail( 'space_id and user_id must both be positive.' );
		}

		$role = SpaceMember::get_role( $space_id, $user_id );

		return Journey_Result::ok(
			[
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'role'     => $role,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List members of a space shaped for {@see Base_Command::render_list()}.
	 *
	 * @param int $space_id Space row ID.
	 */
	public function list_space_members( int $space_id ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 ) {
			return Journey_Result::fail( 'space_id must be positive.' );
		}

		$rows  = SpaceMember::list_by_space( $space_id );
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'space_id'  => (int) $row->space_id,
				'user_id'   => (int) $row->user_id,
				'role'      => (string) ( $row->role ?? '' ),
				'joined_at' => (string) ( $row->joined_at ?? '' ),
			];
		}

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'space_id', 'user_id', 'role', 'joined_at' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List every space the given user has joined, shaped for render_list().
	 *
	 * @param int $user_id User ID to query.
	 */
	public function list_user_spaces( int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$rows  = SpaceMember::list_user_spaces( $user_id );
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'space_id'  => (int) $row->space_id,
				'user_id'   => (int) $row->user_id,
				'role'      => (string) ( $row->role ?? '' ),
				'joined_at' => (string) ( $row->joined_at ?? '' ),
			];
		}

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'space_id', 'user_id', 'role', 'joined_at' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Submit a join request for an approval-gated space. Fires the
	 * `jetonomy_join_request_created` action to match the REST endpoint's
	 * behavior so admin notifications fire regardless of origin.
	 *
	 * @param int    $space_id Space row ID.
	 * @param int    $user_id  Requesting user ID.
	 * @param string $message  Optional message from the requester.
	 */
	public function submit_join_request( int $space_id, int $user_id, string $message = '' ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return Journey_Result::fail( 'space_id and user_id must both be positive.' );
		}

		$id = JoinRequest::create_request( $space_id, $user_id, $message );
		if ( ! $id ) {
			return Journey_Result::fail( 'JoinRequest::create_request() returned 0 — insert failed.' );
		}

		do_action( 'jetonomy_join_request_created', $space_id, $user_id, $message );

		return Journey_Result::ok(
			[
				'id'       => (int) $id,
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'status'   => 'pending',
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Approve a pending join request and materialize the membership.
	 *
	 * Reads the row to extract `space_id`/`user_id`, flips the status to
	 * `approved`, then calls SpaceMember::add() so the requester actually
	 * joins. If SpaceMember::add returns a WP_Error the approval still
	 * stands on the request row, but the result reports the failure.
	 *
	 * @param int $request_id Join request row ID.
	 * @param int $reviewer_id Reviewing user ID.
	 */
	public function approve_join_request( int $request_id, int $reviewer_id ): Journey_Result {
		$start = microtime( true );

		if ( $request_id <= 0 || $reviewer_id <= 0 ) {
			return Journey_Result::fail( 'request_id and reviewer_id must both be positive.' );
		}

		$row = JoinRequest::find( $request_id );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Join request %d not found.', $request_id ) );
		}

		$ok = JoinRequest::approve( $request_id, $reviewer_id );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'JoinRequest::approve(%d) returned false.', $request_id ) );
		}

		$space_id = (int) $row->space_id;
		$user_id  = (int) $row->user_id;

		$added = SpaceMember::add( $space_id, $user_id, 'member' );
		if ( is_wp_error( $added ) ) {
			return Journey_Result::from_wp_error(
				$added,
				[
					'id'       => $request_id,
					'space_id' => $space_id,
					'user_id'  => $user_id,
					'status'   => 'approved',
				]
			);
		}

		return Journey_Result::ok(
			[
				'id'       => $request_id,
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'status'   => 'approved',
				'role'     => 'member',
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Deny a pending join request. Does not touch space membership.
	 *
	 * @param int $request_id  Join request row ID.
	 * @param int $reviewer_id Reviewing user ID.
	 */
	public function deny_join_request( int $request_id, int $reviewer_id ): Journey_Result {
		$start = microtime( true );

		if ( $request_id <= 0 || $reviewer_id <= 0 ) {
			return Journey_Result::fail( 'request_id and reviewer_id must both be positive.' );
		}

		$row = JoinRequest::find( $request_id );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Join request %d not found.', $request_id ) );
		}

		$ok = JoinRequest::deny( $request_id, $reviewer_id );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'JoinRequest::deny(%d) returned false.', $request_id ) );
		}

		return Journey_Result::ok(
			[
				'id'       => $request_id,
				'space_id' => (int) $row->space_id,
				'user_id'  => (int) $row->user_id,
				'status'   => 'denied',
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List pending join requests for a space, shaped for render_list().
	 *
	 * @param int $space_id Space row ID.
	 */
	public function list_pending_requests( int $space_id ): Journey_Result {
		$start = microtime( true );

		if ( $space_id <= 0 ) {
			return Journey_Result::fail( 'space_id must be positive.' );
		}

		$rows  = JoinRequest::list_pending_for_space( $space_id );
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'         => (int) $row->id,
				'space_id'   => (int) $row->space_id,
				'user_id'    => (int) $row->user_id,
				'status'     => (string) ( $row->status ?? '' ),
				'message'    => (string) ( $row->message ?? '' ),
				'created_at' => (string) ( $row->created_at ?? '' ),
			];
		}

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'space_id', 'user_id', 'status', 'message', 'created_at' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
