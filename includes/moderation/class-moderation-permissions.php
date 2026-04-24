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
			return true;
		}
		return Permission_Engine::is_space_privileged( $user_id, $space_id );
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
		if ( user_can( $user_id, 'jetonomy_moderate' ) ) {
			return true;
		}

		$space_id = Flag_Scope::space_id( $flag );
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
		if ( user_can( $user_id, 'jetonomy_moderate' ) ) {
			return true;
		}

		$space_id = Flag_Scope::space_id_for_object( $type, $id );
		if ( null === $space_id ) {
			return false;
		}
		return Permission_Engine::is_space_privileged( $user_id, $space_id );
	}

	/**
	 * Ban / unban / silence actions stay admin-only.
	 *
	 * These are global user-state mutations. Space-level mods should
	 * escalate to admins for them.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function can_issue_ban( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'jetonomy_moderate' );
	}
}
