<?php
/**
 * Moderation journey — flag resolution and user restrictions.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering the moderator workflow: reviewing flagged content,
 * resolving flags (valid/dismissed), and issuing or lifting user restrictions
 * (global bans, space bans, silences, IP bans).
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method accepts
 * primitive inputs or a plain assoc array, delegates to the matching model
 * class (Flag or Restriction), and returns a {@see Journey_Result}. Commands
 * render the result for the terminal; PHPUnit tests read the same fields and
 * assert on them directly.
 *
 * Permission checks are not duplicated here — they belong to the upstream
 * Permission_Engine / admin capability gate. This class is intentionally
 * unguarded so it can be driven from scenarios and impersonated contexts.
 */
final class Moderation_Journey {

	/**
	 * Valid flag status enum values (matches jt_flags.status).
	 */
	private const FLAG_STATUSES = [ 'pending', 'valid', 'dismissed' ];

	/**
	 * Valid flag resolution decision values.
	 *
	 * Mirrors the subset of {@see self::FLAG_STATUSES} that a moderator can
	 * transition a pending flag into.
	 */
	private const FLAG_DECISIONS = [ 'valid', 'dismissed' ];

	/**
	 * Valid restriction type enum values (matches jt_restrictions.type).
	 */
	private const BAN_TYPES = [ 'global_ban', 'space_ban', 'silence', 'ip_ban' ];

	/**
	 * List every pending flag, shaped for render_list().
	 */
	public function list_pending_flags(): Journey_Result {
		$start = microtime( true );

		$rows = Flag::list_pending();

		return Journey_Result::ok(
			[
				'items'   => $this->shape_flag_rows( $rows ),
				'columns' => [ 'id', 'object_type', 'object_id', 'reporter_id', 'reason', 'status', 'created_at' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List flags filtered by status, shaped for render_list().
	 *
	 * @param string $status One of 'pending', 'valid', 'dismissed'.
	 */
	public function list_flags_by_status( string $status ): Journey_Result {
		$start = microtime( true );

		if ( '' === $status ) {
			return Journey_Result::fail( 'status must not be empty.' );
		}
		if ( ! in_array( $status, self::FLAG_STATUSES, true ) ) {
			return Journey_Result::fail(
				sprintf( 'status must be one of: %s.', implode( ', ', self::FLAG_STATUSES ) )
			);
		}

		$rows = Flag::list_by_status( $status );

		return Journey_Result::ok(
			[
				'items'   => $this->shape_flag_rows( $rows ),
				'columns' => [ 'id', 'object_type', 'object_id', 'reporter_id', 'reason', 'status', 'created_at' ],
				'status'  => $status,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Resolve a pending flag, transitioning it to 'valid' or 'dismissed'.
	 *
	 * Honours the same customer contract as the UI moderation queue: a
	 * 'valid' decision trashes the offending post or reply and cascades
	 * across any other pending flags on the same object so the queue
	 * doesn't go on showing stale entries. 'dismissed' leaves content
	 * untouched.
	 *
	 * Permission gating belongs to the upstream caller — the journey
	 * mirrors the rest of this class and stays unguarded so scenarios and
	 * impersonated contexts can drive it directly.
	 *
	 * @param int    $flag_id     Flag row ID.
	 * @param int    $resolver_id User ID of the moderator resolving the flag.
	 * @param string $decision    Either 'valid' or 'dismissed'.
	 */
	public function resolve_flag( int $flag_id, int $resolver_id, string $decision ): Journey_Result {
		$start = microtime( true );

		if ( $flag_id <= 0 ) {
			return Journey_Result::fail( 'flag_id must be positive.' );
		}
		if ( $resolver_id <= 0 ) {
			return Journey_Result::fail( 'resolver_id must be positive.' );
		}
		if ( '' === $decision ) {
			return Journey_Result::fail( 'decision must not be empty.' );
		}
		if ( ! in_array( $decision, self::FLAG_DECISIONS, true ) ) {
			return Journey_Result::fail(
				sprintf( 'decision must be one of: %s.', implode( ', ', self::FLAG_DECISIONS ) )
			);
		}

		$flag = Flag::find( $flag_id );
		if ( ! $flag ) {
			return Journey_Result::fail( sprintf( 'Flag(%d) not found.', $flag_id ) );
		}

		$ok = Flag::resolve( $flag_id, $resolver_id, $decision );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Flag::resolve(%d) returned false.', $flag_id ) );
		}

		$cascaded = 0;
		if ( 'valid' === $decision ) {
			$type = (string) ( $flag->object_type ?? '' );
			$id   = (int) ( $flag->object_id ?? 0 );
			if ( $id > 0 ) {
				if ( 'post' === $type ) {
					Post::update( $id, [ 'status' => 'trash' ] );
				} elseif ( 'reply' === $type ) {
					Reply::update( $id, [ 'status' => 'trash' ] );
				}
				$cascaded = Flag::resolve_siblings_for( $type, $id, $resolver_id, 'valid', $flag_id );
			}
		}

		return Journey_Result::ok(
			[
				'id'                => $flag_id,
				'status'            => $decision,
				'resolved_by'       => $resolver_id,
				'object_type'       => (string) ( $flag->object_type ?? '' ),
				'object_id'         => (int) ( $flag->object_id ?? 0 ),
				'cascaded_resolved' => $cascaded,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Issue a ban or silence restriction against a user.
	 *
	 * For `space_ban`, `$space_id` is required. All other types accept an
	 * optional `$space_id` (usually null for global/IP bans and silences).
	 *
	 * @param int         $user_id    Target user ID.
	 * @param int         $issued_by  Moderator/admin issuing the restriction.
	 * @param string      $type       One of 'global_ban', 'space_ban', 'silence', 'ip_ban'. Default 'global_ban'.
	 * @param int|null    $space_id   Required when $type is 'space_ban'; otherwise optional.
	 * @param string|null $reason     Optional human-readable reason.
	 * @param string|null $expires_at Optional MySQL datetime; null = permanent.
	 */
	public function ban_user(
		int $user_id,
		int $issued_by,
		string $type = 'global_ban',
		?int $space_id = null,
		?string $reason = null,
		?string $expires_at = null
	): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}
		if ( $issued_by <= 0 ) {
			return Journey_Result::fail( 'issued_by must be positive.' );
		}
		if ( '' === $type ) {
			return Journey_Result::fail( 'type must not be empty.' );
		}
		if ( ! in_array( $type, self::BAN_TYPES, true ) ) {
			return Journey_Result::fail(
				sprintf( 'type must be one of: %s.', implode( ', ', self::BAN_TYPES ) )
			);
		}
		if ( 'space_ban' === $type && ( null === $space_id || $space_id <= 0 ) ) {
			return Journey_Result::fail( 'space_id is required and must be positive for space_ban.' );
		}

		$id = Restriction::ban( $user_id, $type, $issued_by, $space_id, $reason, $expires_at );
		if ( ! $id ) {
			return Journey_Result::fail( 'Restriction::ban() returned 0 — insert failed.' );
		}

		return Journey_Result::ok(
			[
				'id'         => (int) $id,
				'user_id'    => $user_id,
				'type'       => $type,
				'issued_by'  => $issued_by,
				'space_id'   => $space_id,
				'reason'     => $reason,
				'expires_at' => $expires_at,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Lift an active restriction by its row ID.
	 *
	 * @param int $restriction_id Restriction row ID.
	 */
	public function unban( int $restriction_id ): Journey_Result {
		$start = microtime( true );

		if ( $restriction_id <= 0 ) {
			return Journey_Result::fail( 'restriction_id must be positive.' );
		}

		$ok = Restriction::remove_ban( $restriction_id );
		if ( ! $ok ) {
			return Journey_Result::fail(
				sprintf( 'Restriction::remove_ban(%d) returned false.', $restriction_id )
			);
		}

		return Journey_Result::ok(
			[ 'id' => $restriction_id ],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Check whether a user is currently banned — globally or from a specific space.
	 *
	 * When `$space_id` is provided, delegates to {@see Restriction::is_space_banned()};
	 * otherwise uses {@see Restriction::is_banned()} for the global check.
	 *
	 * @param int      $user_id  Target user ID.
	 * @param int|null $space_id Optional space ID for a space-scoped check.
	 */
	public function is_banned( int $user_id, ?int $space_id = null ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}
		if ( null !== $space_id && $space_id <= 0 ) {
			return Journey_Result::fail( 'space_id must be positive when provided.' );
		}

		$banned = null === $space_id
			? Restriction::is_banned( $user_id )
			: Restriction::is_space_banned( $user_id, $space_id );

		return Journey_Result::ok(
			[
				'user_id'  => $user_id,
				'space_id' => $space_id,
				'banned'   => $banned,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Shape raw Flag rows into associative arrays for render_list().
	 *
	 * @param object[] $rows Raw $wpdb rows returned by the Flag model.
	 * @return array<int,array<string,mixed>>
	 */
	private function shape_flag_rows( array $rows ): array {
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'          => (int) $row->id,
				'object_type' => (string) ( $row->object_type ?? '' ),
				'object_id'   => (int) ( $row->object_id ?? 0 ),
				'reporter_id' => (int) ( $row->reporter_id ?? 0 ),
				'reason'      => (string) ( $row->reason ?? '' ),
				'status'      => (string) ( $row->status ?? '' ),
				'created_at'  => (string) ( $row->created_at ?? '' ),
			];
		}
		return $items;
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
