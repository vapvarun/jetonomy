<?php
/**
 * Restriction model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Cache;
use function Jetonomy\now;

class Restriction extends Model {

	protected static function table_name(): string {
		return 'restrictions';
	}

	/**
	 * Whether an actor is entitled to restrict this target.
	 *
	 * The cap matrix only ever checked that the ACTOR could moderate, never who
	 * they were allowed to moderate. The editor role carries jetonomy_moderate
	 * by default, so an editor could issue a global_ban against the site
	 * administrator; reject_banned_login() then refuses that account at
	 * wp_signon(), locking the owner out of their own site with recovery only
	 * by DB edit or WP-CLI. Reproduced end to end: the
	 * AJAX handler returned {"success":true}, a jt_restrictions row was written
	 * against user 1, and the authenticate filter then returned
	 * jetonomy_user_banned.
	 *
	 * REST_Moderation_Controller had already grown these rules, but only there -
	 * the AJAX handler the Moderation screen actually posts to, and the CLI
	 * journey, both went straight to the model. Hence the check living here,
	 * where no entry point can route around it.
	 *
	 * The rule is ACTOR-RELATIVE, not an absolute "admins are unbannable":
	 * an administrator may still restrict a peer, and a system-issued
	 * restriction ($issued_by <= 0, e.g. automated moderation) is not
	 * second-guessed. What is refused is escalation - restricting yourself out
	 * of a decision, or a moderator reaching someone at or above their own
	 * level.
	 *
	 * @param int $target_id User being restricted.
	 * @param int $issued_by Actor issuing it; 0 or less means system-issued.
	 * @return true|\WP_Error True when allowed.
	 */
	public static function actor_may_restrict( int $target_id, int $issued_by ) {
		if ( $issued_by <= 0 ) {
			return true;
		}

		if ( $target_id === $issued_by ) {
			return new \WP_Error(
				'jetonomy_cannot_ban_self',
				__( 'You cannot restrict your own account.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		$actor_is_admin = user_can( $issued_by, 'manage_options' );
		if ( $actor_is_admin ) {
			return true;
		}

		if ( user_can( $target_id, 'manage_options' ) ) {
			return new \WP_Error(
				'jetonomy_cannot_ban_admin',
				__( 'Administrators cannot be restricted.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		if ( user_can( $target_id, 'jetonomy_moderate' ) ) {
			return new \WP_Error(
				'jetonomy_cannot_ban_peer_moderator',
				__( 'Only an administrator can restrict another moderator.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate an IP address for an ip_ban.
	 *
	 * An IP ban is enforced for EVERY account at Permission_Engine layer 0,
	 * administrators included, so an operator who bans the address they are
	 * using right now locks themselves out of the community. That is refused
	 * here, where the admin screen, REST and WP-CLI all pass through.
	 *
	 * @param string $ip        Candidate address (IPv4 or IPv6).
	 * @param int    $issued_by Actor issuing it; 0 or less means system-issued.
	 * @return string|\WP_Error The validated address, or why it is refused.
	 */
	public static function validate_ip_ban( string $ip, int $issued_by ) {
		// An IP ban blocks every account on that address, administrators
		// included, and the target-user rules (no admins, no peer moderators)
		// cannot see whose address it is. Only a site administrator issues one.
		if ( $issued_by > 0 && ! user_can( $issued_by, 'manage_options' ) ) {
			return new \WP_Error( 'jetonomy_ip_ban_admin_only', __( 'Only a site administrator can ban an IP address.', 'jetonomy' ), array( 'status' => 403 ) );
		}
		$ip = (string) filter_var( trim( $ip ), FILTER_VALIDATE_IP );
		if ( '' === $ip ) {
			return new \WP_Error( 'jetonomy_invalid_ip', __( 'Enter a valid IPv4 or IPv6 address.', 'jetonomy' ), array( 'status' => 400 ) );
		}
		// Loopback and reserved ranges (127.0.0.0/8, ::1, 0.0.0.0/8 ...) are never a real visitor; behind a
		// local proxy every visitor can arrive from one, so a ban there locks the whole community out.
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE ) ) {
			return new \WP_Error( 'jetonomy_reserved_ip', __( 'This is a loopback or reserved address, not a visitor. Banning it could lock everyone out.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		// shortcut: stored as typed, matched exactly against client_ip(); an IPv6 address written in a
		// non-canonical spelling would not match. Normalise both sides if that is ever reported.

		if ( $issued_by > 0 && \Jetonomy\client_ip() === $ip ) {
			return new \WP_Error( 'jetonomy_cannot_ban_own_ip', __( 'This is the IP address you are using right now. Banning it would lock you out.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		if ( self::is_ip_banned( $ip ) ) {
			return new \WP_Error( 'jetonomy_ip_already_banned', __( 'This IP address is already banned.', 'jetonomy' ), array( 'status' => 409 ) );
		}

		return $ip;
	}

	/**
	 * Issue a ban or silence restriction.
	 *
	 * @param int         $user_id    Target user (ignored and stored as 0 for 'ip_ban').
	 * @param string      $type       Restriction type: 'global_ban', 'space_ban', 'silence' or 'ip_ban'.
	 * @param int         $issued_by  ID of the moderator/admin issuing the restriction.
	 * @param int|null    $space_id   Scoped space ID, or null for global restrictions.
	 * @param string|null $reason     Optional reason text. For 'ip_ban' this is the IP address
	 *                                (the column is_ip_banned() matches) and is required.
	 * @param string|null $expires_at Optional expiry datetime (MySQL format). Null = permanent.
	 * @return int Inserted row ID.
	 */
	public static function ban(
		int $user_id,
		string $type,
		int $issued_by,
		?int $space_id = null,
		?string $reason = null,
		?string $expires_at = null
	): int {
		// An IP ban blocks an address, not an account: it is stored with no member.
		if ( 'ip_ban' === $type ) {
			$user_id = 0;
		}

		$blocked = self::actor_may_restrict( $user_id, $issued_by );
		if ( is_wp_error( $blocked ) ) {
			return 0;
		}

		// is_space_banned() matches an exact space, so a space ban with no
		// space restricts nothing while every caller reports success
		if ( 'space_ban' === $type && ! $space_id ) {
			return 0;
		}

		if ( 'ip_ban' === $type ) {
			$reason = self::validate_ip_ban( (string) $reason, $issued_by );
			if ( is_wp_error( $reason ) ) {
				return 0;
			}
		}

		$data = array(
			'user_id'    => $user_id,
			'type'       => $type,
			'issued_by'  => $issued_by,
			'created_at' => now(),
		);

		if ( null !== $space_id ) {
			$data['space_id'] = $space_id;
		}
		if ( null !== $reason ) {
			$data['reason'] = $reason;
		}
		if ( null !== $expires_at ) {
			$data['expires_at'] = $expires_at;
		}

		$id = static::insert( $data );

		// A restriction issued mid-request must be honored by the next
		// permission check in the same process (moderation flows re-check
		// after banning; the QA suite bans then asserts denial).
		self::reset_memo();
		\Jetonomy\Permissions\Permission_Engine::reset_memo();

		return $id;
	}

	/**
	 * Per-request memo for the four hot restriction primitives.
	 *
	 * Permission_Engine::can() consults these on EVERY verdict, and a page
	 * asks ~5 distinct actions — without the memo that is 15-20 identical
	 * single-row lookups per request. Request-scoped only (never the object
	 * cache): a ban must take effect on the very next request, and within
	 * this request ban()/remove_ban() reset it.
	 *
	 * @var array<string, bool>
	 */
	private static array $memo = array();

	/**
	 * Whether the reset is registered with Cache::flush().
	 *
	 * @var bool
	 */
	private static bool $memo_registered = false;

	/**
	 * Memoize one primitive check for the rest of the request.
	 *
	 * @param string   $key     Memo key.
	 * @param callable $resolve Uncached resolver.
	 */
	private static function memo( string $key, callable $resolve ): bool {
		if ( ! self::$memo_registered ) {
			self::$memo_registered = true;
			Cache::register_memo_reset(
				static function (): void {
					self::$memo = array();
				}
			);
		}
		if ( ! array_key_exists( $key, self::$memo ) ) {
			self::$memo[ $key ] = (bool) $resolve();
		}
		return self::$memo[ $key ];
	}

	/**
	 * Empty the primitive memo. Called by ban()/remove_ban() so a restriction
	 * written mid-request (moderation action, QA fixture) is honored by the
	 * next can() in the same process.
	 */
	public static function reset_memo(): void {
		self::$memo = array();
	}

	/**
	 * Check whether a user is globally banned (active, non-expired).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_banned( int $user_id ): bool {
		return self::memo(
			"banned:{$user_id}",
			static function () use ( $user_id ): bool {
				$now = now();
				$row = static::db()->get_row(
					static::db()->prepare(
						'SELECT id FROM ' . static::table() . '
						WHERE user_id = %d
						  AND type = %s
						  AND (expires_at IS NULL OR expires_at > %s)
						LIMIT 1',
						$user_id,
						'global_ban',
						$now
					)
				);

				return null !== $row;
			}
		);
	}

	/**
	 * SQL that drops users banned site-wide or from one space.
	 *
	 * For candidate lists (who may inherit a space), so the check runs inside
	 * the query instead of once per candidate.
	 *
	 * @param string $user_column Qualified user id column, e.g. 'user_id'.
	 * @param int    $space_id    Space whose own bans also count.
	 * @return string Prepared SQL starting with ' AND'.
	 */
	public static function not_banned_sql( string $user_column, int $space_id ): string {
		return static::db()->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name from the caller, table from static::table().
			" AND {$user_column} NOT IN ( SELECT user_id FROM " . static::table() . "
				WHERE ( type = 'global_ban' OR ( type = 'space_ban' AND space_id = %d ) )
				  AND ( expires_at IS NULL OR expires_at > %s ) )",
			$space_id,
			now()
		);
	}

	/**
	 * Answer is_space_banned() for several spaces with one query, so a list of
	 * topics from many spaces (tag page, drafts, search) does not check the
	 * viewer's ban once per space. Fills the same memo
	 * is_space_banned() reads; spaces already answered are left alone.
	 *
	 * @param int   $user_id   Viewer.
	 * @param int[] $space_ids Space ids.
	 */
	public static function prime_space_bans( int $user_id, array $space_ids ): void {
		$ids = array();
		foreach ( array_unique( array_map( 'intval', $space_ids ) ) as $sid ) {
			if ( $sid > 0 && ! array_key_exists( "space_banned:{$user_id}:{$sid}", self::$memo ) ) {
				$ids[] = $sid;
			}
		}
		if ( $user_id <= 0 || ! $ids ) {
			return;
		}

		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$banned = array_flip(
			array_map(
				'intval',
				static::db()->get_col(
					static::db()->prepare(
						'SELECT space_id FROM ' . static::table() . " WHERE user_id = %d AND type = %s AND space_id IN ({$ph}) AND (expires_at IS NULL OR expires_at > %s)",
						array_merge( array( $user_id, 'space_ban' ), $ids, array( now() ) )
					)
				)
			)
		);
		foreach ( $ids as $sid ) {
			self::memo( "space_banned:{$user_id}:{$sid}", static fn(): bool => isset( $banned[ $sid ] ) );
		}
	}

	/**
	 * Check whether a user is banned from a specific space (active, non-expired).
	 *
	 * @param int $user_id
	 * @param int $space_id
	 * @return bool
	 */
	public static function is_space_banned( int $user_id, int $space_id ): bool {
		return self::memo(
			"space_banned:{$user_id}:{$space_id}",
			static function () use ( $user_id, $space_id ): bool {
				$now = now();
				$row = static::db()->get_row(
					static::db()->prepare(
						'SELECT id FROM ' . static::table() . '
						WHERE user_id = %d
						  AND type = %s
						  AND space_id = %d
						  AND (expires_at IS NULL OR expires_at > %s)
						LIMIT 1',
						$user_id,
						'space_ban',
						$space_id,
						$now
					)
				);

				return null !== $row;
			}
		);
	}

	/**
	 * Check whether a user is currently silenced (active, non-expired).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_silenced( int $user_id ): bool {
		return self::memo(
			"silenced:{$user_id}",
			static function () use ( $user_id ): bool {
				$now = now();
				$row = static::db()->get_row(
					static::db()->prepare(
						'SELECT id FROM ' . static::table() . '
						WHERE user_id = %d
						  AND type = %s
						  AND (expires_at IS NULL OR expires_at > %s)
						LIMIT 1',
						$user_id,
						'silence',
						$now
					)
				);

				return null !== $row;
			}
		);
	}

	/**
	 * Check whether an IP address is currently banned.
	 *
	 * IP bans are stored with type = 'ip_ban' and the IP in the reason column.
	 *
	 * @param string $ip IP address to check.
	 * @return bool
	 */
	public static function is_ip_banned( string $ip ): bool {
		return self::memo(
			"ip_banned:{$ip}",
			static function () use ( $ip ): bool {
				$now = now();
				return (bool) static::db()->get_var(
					static::db()->prepare(
						'SELECT COUNT(*) FROM ' . static::table() . "
						WHERE type = 'ip_ban'
						  AND reason = %s
						  AND (expires_at IS NULL OR expires_at > %s)",
						$ip,
						$now
					)
				);
			}
		);
	}

	/**
	 * List active (non-expired) member restrictions, newest first.
	 *
	 * Powers the moderator ban-management surface (REST GET /moderation/ban).
	 * Defaults to every type a moderator issues and lifts (global_ban,
	 * space_ban, silence, ip_ban; an ip_ban row carries the IP in `reason`);
	 * post_restrict is excluded unless an explicit `type` filter asks for it. Paginated for
	 * big-site safety — served by the user_type_space + expires indexes.
	 *
	 * @param array $args {type?, user_id?, space_id?, limit?, offset?}
	 * @return object[] Raw restriction rows (id, user_id, type, space_id, reason,
	 *                  issued_by, expires_at, created_at).
	 */
	public static function list_active( array $args = array() ): array {
		list( $where, $params ) = self::active_filters( $args );

		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = 'SELECT id, user_id, type, space_id, reason, issued_by, expires_at, created_at
			FROM ' . static::table() . '
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY created_at DESC, id DESC
			LIMIT %d OFFSET %d';

		$params[] = $limit;
		$params[] = $offset;

		return static::db()->get_results( static::db()->prepare( $sql, $params ) ) ?: array();
	}

	/**
	 * Count active (non-expired) member restrictions matching the same filters
	 * as {@see list_active()}. Used for the has_more pagination cursor.
	 *
	 * @param array $args {type?, user_id?, space_id?}
	 * @return int
	 */
	public static function count_active( array $args = array() ): int {
		list( $where, $params ) = self::active_filters( $args );

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where ),
				$params
			)
		);
	}

	/**
	 * Shared WHERE builder for the active-restriction list + count so the two
	 * never drift (a mismatched filter makes has_more lie).
	 *
	 * @param array $args {type?, user_id?, space_id?}
	 * @return array{0: string[], 1: array} [where clauses, prepare params]
	 */
	private static function active_filters( array $args ): array {
		$where  = array( '(expires_at IS NULL OR expires_at > %s)' );
		$params = array( now() );

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = (string) $args['type'];
		} else {
			// Every restriction a moderator can lift; post_restrict is not issued from any screen.
			$where[] = "type IN ('global_ban','space_ban','silence','ip_ban')";
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( isset( $args['space_id'] ) && null !== $args['space_id'] ) {
			$where[]  = 'space_id = %d';
			$params[] = (int) $args['space_id'];
		}

		return array( $where, $params );
	}

	/**
	 * Map user IDs to their single most-severe active restriction type, in one
	 * query. Powers the moderator members directory (each row shows a
	 * banned/silenced badge) without an N+1 per member.
	 *
	 * Severity order: global_ban > space_ban > silence. A user with several active
	 * restrictions is reported at their strongest.
	 *
	 * @param int[] $user_ids
	 * @return array<int,string> [user_id => 'global_ban'|'space_ban'|'silence']
	 */
	public static function active_map_for_users( array $user_ids ): array {
		return array_map( static fn( object $row ): string => $row->type, self::active_rows_for_users( $user_ids ) );
	}

	/**
	 * Map user IDs to their single most-severe active restriction row (id and
	 * type), in one query: the same lookup as active_map_for_users(), with the
	 * id a list needs to offer "Lift restriction".
	 *
	 * @param int[] $user_ids
	 * @return array<int,object{id:int,type:string,space_id:int}>
	 */
	public static function active_rows_for_users( array $user_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		// IDs are ints (intval above), safe to inline; only $now is bound.
		$in   = implode( ',', $ids );
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, type, space_id FROM ' . static::table() . "
				WHERE user_id IN ($in)
				  AND type IN ('global_ban','space_ban','silence')
				  AND (expires_at IS NULL OR expires_at > %s)
				ORDER BY FIELD(type,'global_ban','space_ban','silence'), id DESC",
				now()
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$uid = (int) $row->user_id;
			// First row per user is the most severe (FIELD ordering above).
			if ( ! isset( $map[ $uid ] ) ) {
				$map[ $uid ] = (object) array(
					'id'       => (int) $row->id,
					'type'     => (string) $row->type,
					'space_id' => (int) $row->space_id,
				);
			}
		}

		return $map;
	}

	/**
	 * Lift a restriction by its ID.
	 *
	 * @param int $id Restriction row ID.
	 * @return bool True if a row was deleted.
	 */
	public static function remove_ban( int $id ): bool {
		$result = static::delete( $id );
		self::reset_memo();
		\Jetonomy\Permissions\Permission_Engine::reset_memo();
		return $result;
	}

	/**
	 * Delete up to $limit restrictions whose expiry has passed.
	 *
	 * @param int $limit Max rows per call.
	 * @return int Rows deleted.
	 */
	public static function delete_expired( int $limit ): int {
		return (int) static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . ' WHERE expires_at IS NOT NULL AND expires_at < %s LIMIT %d',
				current_time( 'mysql', true ),
				$limit
			)
		);
	}
}
