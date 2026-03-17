<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use function Jetonomy\table;

class Vote extends Model {

	protected static function table_name(): string {
		return 'votes';
	}

	/**
	 * Cast a vote for an object.
	 *
	 * Handles three scenarios:
	 *   - No existing vote: insert and apply +$value to the target's score.
	 *   - Existing vote with the SAME value: delete (undo) and apply -$value to the target's score.
	 *   - Existing vote with a DIFFERENT value: update and apply the net delta to the target's score.
	 *
	 * @param int    $user_id     Voting user.
	 * @param string $object_type 'post' or 'reply'.
	 * @param int    $object_id   Target object ID.
	 * @param int    $value       Vote value (e.g. 1 or -1).
	 * @return array{action: string, old_value: int|null}
	 */
	public static function cast( int $user_id, string $object_type, int $object_id, int $value ): array {
		$existing = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND object_type = %s AND object_id = %d',
				$user_id,
				$object_type,
				$object_id
			)
		);

		if ( ! $existing ) {
			static::insert(
				[
					'user_id'     => $user_id,
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'value'       => $value,
					'created_at'  => now(),
				]
			);
			static::update_target_score( $object_type, $object_id, $value );

			return [
				'action'    => 'created',
				'old_value' => null,
			];
		}

		$old_value = (int) $existing->value;

		if ( $old_value === $value ) {
			static::delete( (int) $existing->id );
			static::update_target_score( $object_type, $object_id, -$value );

			return [
				'action'    => 'removed',
				'old_value' => $old_value,
			];
		}

		static::update(
			(int) $existing->id,
			[ 'value' => $value ]
		);
		static::update_target_score( $object_type, $object_id, -$old_value + $value );

		return [
			'action'    => 'updated',
			'old_value' => $old_value,
		];
	}

	/**
	 * Get the current vote value a user has cast on an object.
	 *
	 * @param int    $user_id
	 * @param string $object_type
	 * @param int    $object_id
	 * @return int|null The vote value, or null if no vote exists.
	 */
	public static function get_user_vote( int $user_id, string $object_type, int $object_id ): ?int {
		$value = static::db()->get_var(
			static::db()->prepare(
				'SELECT value FROM ' . static::table() . ' WHERE user_id = %d AND object_type = %s AND object_id = %d',
				$user_id,
				$object_type,
				$object_id
			)
		);

		return null !== $value ? (int) $value : null;
	}

	/**
	 * Apply a score delta to the vote_score column of the target post or reply.
	 *
	 * @param string $object_type 'post' or 'reply'.
	 * @param int    $object_id
	 * @param int    $delta
	 */
	private static function update_target_score( string $object_type, int $object_id, int $delta ): void {
		if ( 'post' === $object_type ) {
			$target_table = table( 'posts' );
		} elseif ( 'reply' === $object_type ) {
			$target_table = table( 'replies' );
		} else {
			return;
		}

		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$target_table} SET vote_score = vote_score + %d WHERE id = %d",
				$delta,
				$object_id
			)
		);
	}
}
