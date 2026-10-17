<?php
/**
 * Moderation permission helpers.
 *
 * Dedicated façade for every "can this user do X in moderation" question.
 * Thin layer over Permission_Engine + Flag_Scope so controllers, templates,
 * AJAX handlers, and tests all ask the same questions the same way.
 *
 * Not to be confused with Permission_Engine, which stays focused on
 * low-level space primitives (is_space_admin, is_space_privileged).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Moderation;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Models\SpaceMember;

class Moderation_Permissions {

	/**
	 * Can this user see ANY moderation surface — admin dashboard or at least
	 * one space queue?
	 *
	 * Used by the main nav to decide whether to show a Moderation link at all.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function can_view_any_queue( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		if ( user_can( $user_id, 'jetonomy_moderate' ) ) {
			return true;
		}
		return SpaceMember::has_privileged_membership( $user_id );
	}

	/**
	 * Can this user open the admin cross-space dashboard at /community/mod/?
	 *
	 * Tighter than can_view_any_queue — admin or the global cap only.
	 * Space-level mods/admins do NOT see the dashboard; they moderate per-space.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function can_view_admin_dashboard( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return user_can( $user_id, 'jetonomy_moderate' );
	}

	/**
	 * Can this user open the per-space queue at /community/s/:slug/mod/?
	 *
	 * True for WP admins, jetonomy_moderate cap holders, and space mods/admins
	 * of the target space.
	 *
	 * @param int $user_id
	 * @param int $space_id
	 * @return bool
	 */
	public static function can_view_space_queue( int $user_id, int $space_id ): bool {
		if ( ! $user_id || $space_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'jetonomy_moderate' ) ) {
			return self::staff_can_reach_space( $user_id, $space_id );
		}
		return Permission_Engine::is_space_privileged( $user_id, $space_id );
	}

	/**
	 * Per-request memo for unreadable_space_ids(), keyed by user id.
	 *
	 * @var array<int, int[]>
	 */
	private static array $unreadable_memo = array();

	/**
	 * Whether the memo reset is registered with Cache.
	 *
	 * @var bool
	 */
	private static bool $unreadable_memo_registered = false;

	/**
	 * Spaces a site moderator cannot read (concealed, or banned from).
	 *
	 * Site moderators (jetonomy_moderate) act in every space they can READ,
	 * the same rule Permission_Engine applies to editing and pinning. Every
	 * moderation list, count and action leaves these spaces out, so staff never
	 * see or touch a private space's queue, flags or content without access.
	 * Administrators read everything, so this is empty for them.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function unreadable_space_ids( int $user_id ): array {
		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			return array();
		}
		if ( ! self::$unreadable_memo_registered ) {
			self::$unreadable_memo_registered = true;
			// Same lifetime as Permission_Engine's memo: reset with every cache flush.
			\Jetonomy\Cache::register_memo_reset(
				static function (): void {
					self::$unreadable_memo = array();
				}
			);
		}
		$memo = &self::$unreadable_memo;
		if ( ! isset( $memo[ $user_id ] ) ) {
			// Candidates must cover everything that can deny read, or the lists
			// and the per-object checks disagree: concealed spaces and categories
			// (guest predicate), the viewer's space bans, and - for a globally
			// banned viewer - every space.
			$banned     = \Jetonomy\Models\Restriction::is_banned( $user_id );
			$candidates = \Jetonomy\Models\Space::guest_unreadable_ids( $banned );
			if ( ! $banned ) {
				foreach ( \Jetonomy\Models\Restriction::list_active(
					array(
						'type'    => 'space_ban',
						'user_id' => $user_id,
						'limit'   => 100,
					)
				) as $row ) {
					$candidates[] = (int) $row->space_id;
				}
			}
			$memo[ $user_id ] = array_values(
				array_filter(
					array_unique( $candidates ),
					static fn( int $space_id ): bool => ! Permission_Engine::can( $user_id, 'read', $space_id )
				)
			);
		}
		return $memo[ $user_id ];
	}

	/**
	 * Scope for a site moderator's cross-space lists: every space but the
	 * ones they cannot read. Null (no filter) for administrators.
	 *
	 * @param int $user_id User ID.
	 * @return array{skip_spaces:int[]}|null
	 */
	public static function staff_scope( int $user_id ): ?array {
		$exclude = self::unreadable_space_ids( $user_id );
		return $exclude ? array( 'skip_spaces' => $exclude ) : null;
	}

	/**
	 * Whether a site moderator may act in this space (they can read it).
	 *
	 * @param int $user_id  User ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	private static function staff_can_reach_space( int $user_id, int $space_id ): bool {
		return user_can( $user_id, 'manage_options' ) || Permission_Engine::can( $user_id, 'read', $space_id );
	}

	/**
	 * The account restriction that bars this user from moderating, or null.
	 *
	 * Moderation is a write, so a silenced, banned or space-banned moderator
	 * is refused with the same reason the member surfaces give
	 * (Permission_Engine::restriction_error). Removing others' content is the
	 * write silencing takes away, and every moderation action is asked as it.
	 *
	 * @param int      $user_id  User ID.
	 * @param int|null $space_id Space the action is in.
	 * @return \WP_Error|null
	 */
	public static function restriction_error( int $user_id, ?int $space_id = null ): ?\WP_Error {
		return Permission_Engine::restriction_error( $user_id, 'delete_others_posts', $space_id );
	}

	/**
	 * Can this user resolve / dismiss a specific flag?
	 *
	 * Admin + cap holders always. Space mods only when the flag's object
	 * lives in a space they are privileged on. User-type flags (no space)
	 * stay admin / cap only.
	 *
	 * @param int    $user_id
	 * @param object $flag
	 * @return bool
	 */
	public static function can_act_on_flag( int $user_id, object $flag ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$space_id = Flag_Scope::space_id( $flag );
		if ( user_can( $user_id, 'jetonomy_moderate' ) ) {
			// A flag on a member (no space) stays cap-only; content flags need read access.
			return null === $space_id || self::staff_can_reach_space( $user_id, $space_id );
		}

		if ( null === $space_id ) {
			return false;
		}
		return Permission_Engine::is_space_privileged( $user_id, $space_id );
	}

	/**
	 * Can this user approve / spam / trash a post or reply?
	 *
	 * @param int    $user_id
	 * @param string $type 'post' or 'reply'
	 * @param int    $id   Object row ID.
	 * @return bool
	 */
	public static function can_act_on_object( int $user_id, string $type, int $id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$space_id = Flag_Scope::space_id_for_object( $type, $id );
		if ( null === $space_id ) {
			return false;
		}
		if ( user_can( $user_id, 'jetonomy_moderate' ) ) {
			return self::staff_can_reach_space( $user_id, $space_id );
		}
		return Permission_Engine::is_space_privileged( $user_id, $space_id );
	}
}
