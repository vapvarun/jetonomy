<?php
namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Cache;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\UserProfile;

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
	 * Results are cached for 60 seconds per user/action/space combination.
	 *
	 * @param int         $user_id  WP user ID to check.
	 * @param string      $action   Action name (without 'jetonomy_' prefix for WP cap check).
	 * @param int|null    $space_id Optional space context.
	 * @return bool
	 */
	public static function can( int $user_id, string $action, ?int $space_id = null ): bool {
		$cache_key = "perm:{$user_id}:{$action}:" . ( $space_id ?? 0 );
		$cached    = Cache::get( $cache_key );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$result = self::resolve( $user_id, $action, $space_id );
		// Store as 1/0 so we can distinguish a cached false from a cache miss.
		Cache::set( $cache_key, $result ? 1 : 0, 60 );
		return $result;
	}

	/**
	 * Internal uncached resolution — called by can().
	 */
	private static function resolve( int $user_id, string $action, ?int $space_id ): bool {
		// Layer 0: IP ban check.
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if ( $ip && Restriction::is_ip_banned( $ip ) ) {
			return false;
		}

		// Layer 0: Global ban.
		if ( $user_id && Restriction::is_banned( $user_id ) ) {
			return false;
		}

		// Layer 0b: Silence check — can read but not write.
		if ( $user_id && class_exists( 'Jetonomy\Models\Restriction' ) && Restriction::is_silenced( $user_id ) ) {
			$write_actions = [ 'create_posts', 'create_replies', 'vote', 'flag', 'create_spaces', 'edit_others_posts', 'delete_others_posts', 'close_posts', 'pin_posts', 'move_posts' ];
			if ( in_array( $action, $write_actions, true ) ) {
				return false;
			}
			// Allow read actions to continue through the normal flow.
		}

		// WP admin bypass — skip all further checks.
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Layer 1: WordPress capability.
		// Guest users (user_id=0) skip the WP cap check for 'read' actions;
		// public space visibility is evaluated in Layer 2 instead.
		if ( $user_id && ! user_can( $user_id, 'jetonomy_' . $action ) ) {
			return false;
		}

		// Guests may only read — reject any non-read action immediately.
		if ( ! $user_id && 'read' !== $action ) {
			return false;
		}

		// No space context — WP cap is sufficient (logged-in), or deny guests.
		if ( null === $space_id ) {
			return (bool) $user_id;
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

		// Check access rules (membership, capability, trust level rules).
		$access = AccessRule::resolve_access( $user_id, $space_id );
		if ( $access ) {
			// Access rule grants access — check if sufficient for the action.
			$grants_map = [
				'read'        => [ 'read' ],
				'participate' => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag' ],
				'full'        => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag', 'edit_others_posts', 'close_posts', 'pin_posts' ],
			];
			$allowed_actions = $grants_map[ $access['grants'] ] ?? [ 'read' ];
			if ( in_array( $action, $allowed_actions, true ) ) {
				return true;
			}
		}

		// Public space — no membership required for read.
		// Public + open join_policy — logged-in users may also participate.
		if ( 'public' === $space->visibility ) {
			if ( 'read' === $action ) {
				return true;
			}
			$is_open = 'open' === ( $space->join_policy ?? 'open' );
			if ( $is_open && $user_id ) {
				$open_actions = [ 'create_posts', 'create_replies', 'vote', 'flag' ];
				if ( in_array( $action, $open_actions, true ) ) {
					return true;
				}
			}
		}

		// Resolve space role and check against role permissions.
		$role = SpaceMember::get_role( $space_id, $user_id );
		if ( ! $role ) {
			// Non-member — only read is allowed (private/hidden already blocked above).
			return 'read' === $action;
		}

		// Layer 3: Trust level gates.
		$profile     = UserProfile::find_by_user( $user_id );
		$trust_level = $profile ? (int) $profile->trust_level : 0;

		$trust_requirements = [
			'edit_others_posts' => 3,
			'move_posts'        => 3,
			'close_posts'       => 3,
			'pin_posts'         => 3,
			'create_spaces'     => 4,
		];

		if ( isset( $trust_requirements[ $action ] ) && $trust_level < $trust_requirements[ $action ] ) {
			// Space moderators/admins bypass trust requirements.
			if ( ! in_array( $role, [ 'moderator', 'admin' ], true ) ) {
				return false;
			}
		}

		return in_array( $action, self::SPACE_ROLE_PERMS[ $role ] ?? [], true );
	}
}
