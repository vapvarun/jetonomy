<?php
/**
 * Vote model.
 *
 * @package Jetonomy
 */

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
	public static function cast( int $user_id, string $object_type, int $object_id, int $value ): array|\WP_Error {
		/**
		 * Filter whether a vote should proceed. Return WP_Error to abort.
		 *
		 * @param bool   $proceed     Whether to proceed (default true).
		 * @param int    $user_id     Voting user.
		 * @param string $object_type 'post' or 'reply'.
		 * @param int    $object_id   Target object ID.
		 * @param int    $value       Vote value (e.g. 1 or -1).
		 */
		$proceed = apply_filters( 'jetonomy_before_vote', true, $user_id, $object_type, $object_id, $value );
		if ( is_wp_error( $proceed ) ) {
			return $proceed;
		}

		$existing = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND object_type = %s AND object_id = %d',
				$user_id,
				$object_type,
				$object_id
			)
		);

		$started = static::db()->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $started ) {
			// Proceed without transaction — graceful degradation.
			$started = null;
		}

		try {
			if ( ! $existing ) {
				$result = static::insert(
					[
						'user_id'     => $user_id,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'value'       => $value,
						'created_at'  => now(),
					]
				);
				if ( false === $result ) {
					if ( null !== $started ) {
						static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					return new \WP_Error( 'jetonomy_vote_failed', __( 'Failed to record vote.', 'jetonomy' ), [ 'status' => 500 ] );
				}
				static::update_target_score( $object_type, $object_id, $value );
				if ( static::db()->last_error ) {
					if ( null !== $started ) {
						static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					return new \WP_Error( 'jetonomy_vote_failed', __( 'Failed to update score.', 'jetonomy' ), [ 'status' => 500 ] );
				}
				if ( null !== $started ) {
					static::db()->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}

				/**
				 * Fires after a voter casts a new vote.
				 *
				 * Lets gamification reward the voter directly (the receiver
				 * is already covered by reputation). $vote_type is the raw
				 * value the voter chose: 1 for an upvote, -1 for a downvote.
				 *
				 * @param int    $vote_type   Raw vote value (1 or -1).
				 * @param string $object_type 'post' or 'reply'.
				 * @param int    $object_id   Target object ID.
				 * @param int    $voter_id    Voting user ID.
				 */
				do_action( 'jetonomy_vote_cast', $value, $object_type, $object_id, $user_id );

				return [
					'action'    => 'created',
					'old_value' => null,
				];
			}

			$old_value = (int) $existing->value;

			if ( $old_value === $value ) {
				$result = static::delete( (int) $existing->id );
				if ( false === $result ) {
					if ( null !== $started ) {
						static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					return new \WP_Error( 'jetonomy_vote_failed', __( 'Failed to remove vote.', 'jetonomy' ), [ 'status' => 500 ] );
				}
				static::update_target_score( $object_type, $object_id, -$value );
				if ( static::db()->last_error ) {
					if ( null !== $started ) {
						static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					return new \WP_Error( 'jetonomy_vote_failed', __( 'Failed to update score.', 'jetonomy' ), [ 'status' => 500 ] );
				}
				if ( null !== $started ) {
					static::db()->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}

				/**
				 * Fires after a voter retracts their existing vote.
				 *
				 * @param int    $vote_type   Raw value of the retracted vote (1 or -1).
				 * @param string $object_type 'post' or 'reply'.
				 * @param int    $object_id   Target object ID.
				 * @param int    $voter_id    Voting user ID.
				 */
				do_action( 'jetonomy_vote_retracted', $old_value, $object_type, $object_id, $user_id );

				return [
					'action'    => 'removed',
					'old_value' => $old_value,
				];
			}

			$result = static::update(
				(int) $existing->id,
				[ 'value' => $value ]
			);
			if ( false === $result ) {
				if ( null !== $started ) {
					static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				return new \WP_Error( 'jetonomy_vote_failed', __( 'Failed to update vote.', 'jetonomy' ), [ 'status' => 500 ] );
			}
			static::update_target_score( $object_type, $object_id, -$old_value + $value );
			if ( static::db()->last_error ) {
				if ( null !== $started ) {
					static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				return new \WP_Error( 'jetonomy_vote_failed', __( 'Failed to update score.', 'jetonomy' ), [ 'status' => 500 ] );
			}
			if ( null !== $started ) {
				static::db()->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}

			// Toggling between up <-> down is one retract + one cast on the
			// voter side. Firing both makes downstream challenge counters
			// (e.g. "10 votes this week") match the user's actual actions.
			do_action( 'jetonomy_vote_retracted', $old_value, $object_type, $object_id, $user_id );
			do_action( 'jetonomy_vote_cast', $value, $object_type, $object_id, $user_id );

			return [
				'action'    => 'updated',
				'old_value' => $old_value,
			];
		} catch ( \Throwable $e ) {
			if ( null !== $started ) {
				static::db()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
			return new \WP_Error( 'jetonomy_vote_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
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
	 * Batch vote lookup — map of $object_ids the user has voted on to value.
	 *
	 * One query for a whole page, so list/feed enrichment never runs a per-row
	 * get_user_vote() (N+1). Missing IDs are simply absent from the map (the
	 * caller treats absence as 0).
	 *
	 * @since 1.6.0
	 * @param int    $user_id     Voter.
	 * @param string $object_type 'post' | 'reply'.
	 * @param int[]  $object_ids  Candidate object IDs.
	 * @return array<int,int> Map of object_id => vote value (-1|1).
	 */
	public static function user_votes_map( int $user_id, string $object_type, array $object_ids ): array {
		$object_ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		if ( $user_id <= 0 || empty( $object_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT object_id, value FROM ' . static::table() . " WHERE user_id = %d AND object_type = %s AND object_id IN ({$placeholders})",
				$user_id,
				$object_type,
				...$object_ids
			)
		);

		$map = array();
		foreach ( $rows ?: array() as $row ) {
			$map[ (int) $row->object_id ] = (int) $row->value;
		}
		return $map;
	}

	/**
	 * List posts voted on by a user (upvotes only), with post + space info.
	 *
	 * @param int $user_id Voter user ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Pagination offset.
	 * @return object[]
	 */
	public static function list_by_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		$votes_tbl  = static::table();
		$posts_tbl  = table( 'posts' );
		$spaces_tbl = table( 'spaces' );

		// Space-visibility + per-post is_private gate: posts the voter upvoted
		// that live in a private/hidden space (or are private posts) must not
		// leak to non-member / non-author viewers of this user's Votes tab.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 'sp' );
		[ $priv_sql, $priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );

		$gate_sql    = '';
		$gate_params = array();
		if ( '1=1' !== $space_vis_sql ) {
			$gate_sql   .= ' AND ' . $space_vis_sql;
			$gate_params = array_merge( $gate_params, $space_vis_params );
		}
		if ( '' !== $priv_sql ) {
			$gate_sql   .= ' AND ' . $priv_sql;
			$gate_params = array_merge( $gate_params, $priv_params );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT v.value, v.created_at AS voted_at, p.id AS post_id, p.title, p.slug AS post_slug,
				        p.vote_score, p.reply_count, sp.slug AS space_slug, sp.title AS space_title
				 FROM {$votes_tbl} v
				 INNER JOIN {$posts_tbl} p ON p.id = v.object_id
				 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
				 WHERE v.user_id = %d AND v.object_type = 'post' AND v.value > 0{$gate_sql}
				 ORDER BY v.created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				...array_merge( $gate_params, array( $limit, $offset ) )
			)
		) ?: [];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
