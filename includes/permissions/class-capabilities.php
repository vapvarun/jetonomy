<?php
/**
 * Capability registration and mapping.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	private const ROLE_MAP = [
		'subscriber'    => [
			'jetonomy_read',
			'jetonomy_create_posts',
			'jetonomy_create_replies',
			'jetonomy_edit_own_posts',
			'jetonomy_delete_own_posts',
			'jetonomy_vote',
			'jetonomy_flag',
			'jetonomy_join_spaces',
		],
		'contributor'   => [ 'jetonomy_upload_media' ],
		'author'        => [ 'jetonomy_create_spaces' ],
		'editor'        => [
			'jetonomy_edit_others_posts',
			'jetonomy_delete_others_posts',
			'jetonomy_moderate',
			'jetonomy_manage_users',
			'jetonomy_move_posts',
			'jetonomy_close_posts',
			'jetonomy_pin_posts',
		],
		'administrator' => [
			'jetonomy_manage_settings',
			'jetonomy_manage_categories',
			'jetonomy_manage_spaces',
			'jetonomy_manage_badges',
			'jetonomy_view_analytics',
			'jetonomy_manage_extensions',
		],
	];

	/**
	 * Register all Jetonomy capabilities on WordPress roles.
	 *
	 * Capabilities are cumulative: each role inherits all caps from the roles
	 * listed above it in the ROLE_MAP hierarchy.
	 */
	public static function register(): void {
		$cumulative = [];
		foreach ( self::ROLE_MAP as $role_name => $caps ) {
			$cumulative = array_merge( $cumulative, $caps );
			$role       = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $cumulative as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Roles that hold `jetonomy_create_spaces` in the live roles registry.
	 *
	 * Read from wp_roles() rather than ROLE_MAP so a custom role the site
	 * granted the cap to counts too. Used to seed the front-end allow-list on
	 * upgrade, so a site's effective permissions do not change under it.
	 *
	 * @return string[]
	 */
	public static function roles_with_create_spaces(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return [];
		}

		$out = [];
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( $role->has_cap( 'jetonomy_create_spaces' ) || $role->has_cap( 'manage_options' ) ) {
				$out[] = (string) $slug;
			}
		}

		return $out;
	}

	/**
	 * Whether a user may create a space from the FRONT END.
	 *
	 * The single gate for the REST create route, the /new-space/ form, and the
	 * user-panel "Create space" link. All three used to OR in
	 * `current_user_can( 'jetonomy_create_spaces' )` before consulting the
	 * admin's allow-list — and since that cap is granted cumulatively to
	 * author and up, unticking Author or Editor under Settings → General did
	 * nothing at all (Basecamp 10118734782). The setting could only ever
	 * restrict subscribers and contributors, which was not what it said.
	 *
	 * The cap still gates programmatic paths (CLI, Abilities). It no longer
	 * overrides what the site owner configured for the front end.
	 *
	 * @param int $user_id WP user ID. 0 = current user.
	 * @return bool
	 */
	public static function can_create_space_frontend( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		$can = false;
		if ( $user_id > 0 ) {
			if ( user_can( $user_id, 'manage_options' ) ) {
				// Site admins always qualify; the setting describes who ELSE does.
				$can = true;
			} else {
				$settings = get_option( 'jetonomy_settings', [] );
				$allowed  = isset( $settings['frontend_space_creation_roles'] )
					? array_filter( array_map( 'sanitize_key', (array) $settings['frontend_space_creation_roles'] ) )
					: [];

				$user = $allowed ? get_userdata( $user_id ) : null;
				$can  = $user && ! empty( $user->roles )
					&& count( array_intersect( (array) $user->roles, $allowed ) ) > 0;
			}
		}

		/**
		 * Filter whether a user may create a space from the front end.
		 *
		 * The extension point for membership tiers, per-user grants, or any
		 * rule roles cannot express. Runs after the admin's role allow-list,
		 * so a filter can both grant and revoke.
		 *
		 * @param bool $can     Whether the user qualifies.
		 * @param int  $user_id WP user ID (0 when logged out).
		 */
		return (bool) apply_filters( 'jetonomy_can_create_space_frontend', $can, $user_id );
	}

	/**
	 * Every Jetonomy capability, flattened from ROLE_MAP.
	 *
	 * ROLE_MAP stays the single source of truth. Headless clients (the mobile
	 * app) have to know what the signed-in member may actually do, and
	 * re-listing the caps anywhere else would guarantee the two lists drift.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_values( array_unique( array_merge( ...array_values( self::ROLE_MAP ) ) ) );
	}

	/**
	 * Resolve a user's capabilities as a `cap => bool` map for REST clients.
	 *
	 * `manage_options` is folded in because it is the cap the admin surfaces
	 * gate on, and it is a WP core cap rather than one of ours.
	 *
	 * @param int $user_id WP user ID.
	 * @return array<string, bool>
	 */
	public static function map_for_user( int $user_id ): array {
		$out = [];
		foreach ( array_merge( self::all(), [ 'manage_options' ] ) as $cap ) {
			$out[ $cap ] = user_can( $user_id, $cap );
		}
		return $out;
	}
}
