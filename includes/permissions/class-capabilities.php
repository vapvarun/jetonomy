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
	 * Option holding the site owner's role => caps overrides.
	 *
	 * Absent role key = that role uses the ROLE_MAP default. Present key =
	 * the role's Jetonomy caps are EXACTLY the stored list (an empty list is
	 * a valid "none" choice). Administrator is never stored: admins always
	 * hold every cap, so a site owner cannot lock themselves out of the
	 * mapping screen (Basecamp 9725751235 - the mapping used to be
	 * hard-coded and the settings card literally said "This mapping is
	 * fixed").
	 */
	public const ROLE_CAPS_OPTION = 'jetonomy_role_caps';

	/**
	 * The default cumulative cap set per core role, from ROLE_MAP.
	 *
	 * @return array<string, string[]> role => caps.
	 */
	public static function default_map(): array {
		$out        = [];
		$cumulative = [];
		foreach ( self::ROLE_MAP as $role_name => $caps ) {
			$cumulative        = array_merge( $cumulative, $caps );
			$out[ $role_name ] = array_values( $cumulative );
		}
		return $out;
	}

	/**
	 * The mapping that should be live: defaults overlaid with the owner's
	 * saved overrides. Administrator is pinned to the full cap list.
	 *
	 * @return array<string, string[]> role => caps.
	 */
	public static function effective_map(): array {
		$map       = self::default_map();
		$overrides = get_option( self::ROLE_CAPS_OPTION, [] );
		if ( is_array( $overrides ) ) {
			foreach ( $overrides as $role => $caps ) {
				if ( 'administrator' === $role ) {
					continue;
				}
				$map[ (string) $role ] = array_values( array_intersect( self::all(), array_map( 'sanitize_key', (array) $caps ) ) );
			}
		}
		$map['administrator'] = self::all();
		return $map;
	}

	/**
	 * Register all Jetonomy capabilities on WordPress roles.
	 *
	 * Defaults are cumulative per ROLE_MAP; the owner's saved overrides
	 * replace a role's set outright. This SYNCS rather than only adding:
	 * a Jetonomy cap a role should no longer hold is removed, so unticking
	 * a box on the mapping screen actually revokes.
	 */
	public static function register(): void {
		// Same guard roles_with_create_spaces() carries: in a minimal boot
		// context (the WP-stub smoke test) the roles API may not exist yet.
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		// First sync on a site that predates the editable mapping: snapshot
		// any live role whose Jetonomy caps differ from the ROLE_MAP default
		// (hand-granted custom roles included) into the option, so syncing
		// never changes a site's effective permissions out from under it -
		// same seeding discipline roles_with_create_spaces() established.
		//
		// A role holding NO Jetonomy caps is deliberately NOT seeded. On a
		// fresh install that is every role, and an empty snapshot is not a
		// preserved permission set - it is an override saying "this role gets
		// nothing", which effective_map() then honours and the sync loop below
		// enforces. Seeding it turned every new site into one where subscribers
		// could not read and editors could not moderate, permanently, because
		// the option now exists and this branch never runs again. There is
		// nothing to preserve for a role with no caps, so it falls through to
		// the defaults; only a role that actually HOLDS Jetonomy caps has a
		// permission set worth protecting from the sync.
		if ( false === get_option( self::ROLE_CAPS_OPTION, false ) ) {
			$defaults = self::default_map();
			$seed     = [];
			foreach ( wp_roles()->role_objects as $slug => $role ) {
				if ( 'administrator' === $slug ) {
					continue;
				}
				$live = array_values( array_filter( self::all(), static fn( $cap ) => $role->has_cap( $cap ) ) );
				if ( empty( $live ) ) {
					continue;
				}
				$def = $defaults[ $slug ] ?? [];
				sort( $live );
				$def_sorted = $def;
				sort( $def_sorted );
				if ( $live !== $def_sorted ) {
					$seed[ $slug ] = $live;
				}
			}
			update_option( self::ROLE_CAPS_OPTION, $seed, false );
		}

		$targets = self::effective_map();
		$all     = self::all();

		foreach ( wp_roles()->role_objects as $slug => $role ) {
			// Roles outside the map (custom roles) default to no Jetonomy caps
			// unless the owner saved a set for them.
			$want = isset( $targets[ $slug ] ) ? $targets[ $slug ] : [];
			foreach ( $all as $cap ) {
				$should = in_array( $cap, $want, true );
				$has    = $role->has_cap( $cap );
				if ( $should && ! $has ) {
					$role->add_cap( $cap );
				} elseif ( ! $should && $has ) {
					$role->remove_cap( $cap );
				}
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
	 * Human labels for every capability, for the mapping screen.
	 *
	 * @return array<string, string> cap => label.
	 */
	public static function labels(): array {
		return [
			'jetonomy_read'                => __( 'Read the community', 'jetonomy' ),
			'jetonomy_create_posts'        => __( 'Create posts', 'jetonomy' ),
			'jetonomy_create_replies'      => __( 'Create replies', 'jetonomy' ),
			'jetonomy_edit_own_posts'      => __( 'Edit own posts', 'jetonomy' ),
			'jetonomy_delete_own_posts'    => __( 'Delete own posts', 'jetonomy' ),
			'jetonomy_vote'                => __( 'Vote', 'jetonomy' ),
			'jetonomy_flag'                => __( 'Report content', 'jetonomy' ),
			'jetonomy_join_spaces'         => __( 'Join spaces', 'jetonomy' ),
			'jetonomy_upload_media'        => __( 'Upload media', 'jetonomy' ),
			'jetonomy_create_spaces'       => __( 'Create spaces (programmatic)', 'jetonomy' ),
			'jetonomy_edit_others_posts'   => __( "Edit others' posts", 'jetonomy' ),
			'jetonomy_delete_others_posts' => __( "Delete others' posts", 'jetonomy' ),
			'jetonomy_moderate'            => __( 'Moderate (queue, flags, bans)', 'jetonomy' ),
			'jetonomy_manage_users'        => __( 'Manage community users', 'jetonomy' ),
			'jetonomy_move_posts'          => __( 'Move posts between spaces', 'jetonomy' ),
			'jetonomy_close_posts'         => __( 'Close topics', 'jetonomy' ),
			'jetonomy_pin_posts'           => __( 'Pin topics', 'jetonomy' ),
			'jetonomy_manage_settings'     => __( 'Manage settings', 'jetonomy' ),
			'jetonomy_manage_categories'   => __( 'Manage categories', 'jetonomy' ),
			'jetonomy_manage_spaces'       => __( 'Manage all spaces', 'jetonomy' ),
			'jetonomy_manage_badges'       => __( 'Manage badges', 'jetonomy' ),
			'jetonomy_view_analytics'      => __( 'View analytics', 'jetonomy' ),
			'jetonomy_manage_extensions'   => __( 'Manage extensions', 'jetonomy' ),
		];
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
		// Cached 60s per user (plan WP4.13) — /users/me runs on app boot and
		// screen mounts, and this walks the whole capability set through
		// user_can() each time. A whole-payload /users/me cache was rejected
		// by the ownership map (it would stack over the profile/user row
		// caches); this is the one genuinely uncached expensive part. Busted
		// on role change by the boot-level set_user_role hook (caps:{id}).
		$cached = \Jetonomy\Cache::get( "caps:{$user_id}" );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = [];
		foreach ( array_merge( self::all(), [ 'manage_options' ] ) as $cap ) {
			$out[ $cap ] = user_can( $user_id, $cap );
		}

		\Jetonomy\Cache::set( "caps:{$user_id}", $out, 60 );
		return $out;
	}
}
