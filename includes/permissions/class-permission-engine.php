<?php
namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Restriction;

/**
 * Three-layer permission resolver.
 *
 * Layer 0 — Global ban check (via Restriction model).
 * Layer 1 — WordPress capability check (jetonomy_{action}).
 * Layer 2 — Space visibility + space-role permission check.
 *
 * WP admins (manage_options) bypass layers 1 and 2.
 */
class Permission_Engine {

	/**
	 * Actions permitted per space role.
	 *
	 * Each higher role includes all actions of the roles below it.
	 */
	private const SPACE_ROLE_PERMS = [
		'viewer'    => [ 'read' ],
		'member'    => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag' ],
		'moderator' => [
			'read', 'create_posts', 'create_replies', 'vote', 'flag',
			'edit_others_posts', 'delete_others_posts', 'close_posts',
			'pin_posts', 'move_posts',
		],
		'admin'     => [
			'read', 'create_posts', 'create_replies', 'vote', 'flag',
			'edit_others_posts', 'delete_others_posts', 'close_posts',
			'pin_posts', 'move_posts', 'manage_spaces',
		],
	];

	/**
	 * Determine whether a user is allowed to perform an action.
	 *
	 * @param int         $user_id  WP user ID to check.
	 * @param string      $action   Action name (without 'jetonomy_' prefix for WP cap check).
	 * @param int|null    $space_id Optional space context.
	 * @return bool
	 */
	public static function can( int $user_id, string $action, ?int $space_id = null ): bool {
		// Layer 0: Global ban.
		if ( Restriction::is_banned( $user_id ) ) {
			return false;
		}

		// WP admin bypass — skip all further checks.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Layer 1: WordPress capability.
		if ( ! user_can( $user_id, 'jetonomy_' . $action ) ) {
			return false;
		}

		// No space context — WP cap is sufficient.
		if ( null === $space_id ) {
			return true;
		}

		// Layer 2: Space visibility + membership.
		$space = Space::find( $space_id );
		if ( ! $space ) {
			return false;
		}

		// Private / hidden spaces require membership.
		if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
			if ( ! SpaceMember::is_member( $space_id, $user_id ) ) {
				return false;
			}
		}

		// Public space read — no membership required.
		if ( 'read' === $action && 'public' === $space->visibility ) {
			return true;
		}

		// Resolve space role and check against role permissions.
		$role = SpaceMember::get_role( $space_id, $user_id );
		if ( ! $role ) {
			// Non-member of a public space may only read.
			return 'read' === $action;
		}

		return in_array( $action, self::SPACE_ROLE_PERMS[ $role ] ?? [], true );
	}
}
