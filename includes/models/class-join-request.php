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
		$request = self::find( $id );
		$ok      = self::update(
			$id,
			[
				'status'      => 'approved',
				'reviewed_by' => $reviewed_by,
				'reviewed_at' => now(),
			]
		);
		if ( $ok && $request ) {
			// Tell the requester their request was approved — no listener existed,
			// so a member never learned the outcome unless they revisited the space.
			do_action( 'jetonomy_join_request_approved', (int) $request->space_id, (int) $request->user_id, $reviewed_by );
		}
		return $ok;
	}

	/**
	 * Deny a join request.
	 *
	 * @param int $id          Request row ID.
	 * @param int $reviewed_by User ID of the reviewer.
	 * @return bool
	 */
	public static function deny( int $id, int $reviewed_by ): bool {
		$request = self::find( $id );
		$ok      = self::update(
			$id,
			[
				'status'      => 'denied',
				'reviewed_by' => $reviewed_by,
				'reviewed_at' => now(),
			]
		);
		if ( $ok && $request ) {
			do_action( 'jetonomy_join_request_denied', (int) $request->space_id, (int) $request->user_id, $reviewed_by );
		}
		return $ok;
	}
}
