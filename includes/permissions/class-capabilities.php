<?php
/**
 * Capability registration and mapping.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	private const ROLE_MAP = array(
		'subscriber'    => array(
			'jetonomy_read',
			'jetonomy_upload_media',
			'jetonomy_create_posts',
			'jetonomy_create_replies',
			'jetonomy_edit_own_posts',
			'jetonomy_delete_own_posts',
			'jetonomy_vote',
			'jetonomy_flag',
			'jetonomy_join_spaces',
		),
		'contributor'   => array(),
		'author'        => array(),
		'editor'        => array(
			'jetonomy_edit_others_posts',
			'jetonomy_delete_others_posts',
			'jetonomy_moderate',
			'jetonomy_manage_users',
			'jetonomy_move_posts',
			'jetonomy_close_posts',
			'jetonomy_pin_posts',
		),
		'administrator' => array(
			'jetonomy_manage_settings',
			'jetonomy_manage_categories',
			'jetonomy_manage_spaces',
			'jetonomy_manage_badges',
			'jetonomy_view_analytics',
			'jetonomy_manage_extensions',
		),
	);

	/**
	 * Option holding the site owner's role => caps overrides.
	 *
	 * Absent role key = that role uses the ROLE_MAP default. Present key =
	 * the role's Jetonomy caps are EXACTLY the stored list (an empty list is
	 * a valid "none" choice). The Permissions tab never stores a row the
	 * owner left untouched, so third-party roles keep the engine's space
	 * membership fallback until someone configures them. Administrator is never stored: admins always
	 * hold every cap, so a site owner cannot lock themselves out of the
	 * mapping screen.
	 */
	public const ROLE_CAPS_OPTION = 'jetonomy_role_caps';

	/**
	 * Community uploads (composer images, avatars, covers, Pro attachments)
	 * follow the Upload media box alone, so unticking it stops every role,
	 * Authors and Editors included, whatever their wp-admin Media rights.
	 * Site admins always keep it.
	 */
	public const UPLOAD_CAPS = array( 'jetonomy_upload_media', 'manage_options' );

	/**
	 * The default cumulative cap set per core role, from ROLE_MAP.
	 *
	 * @return array<string, string[]> role => caps.
	 */
	public static function default_map(): array {
		$out        = array();
		$cumulative = array();
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
		$overrides = get_option( self::ROLE_CAPS_OPTION, array() );
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
	 * Whether the owner has deliberately configured any of this user's roles
	 * on the Permissions screen.
	 *
	 * Reads the ROLE_CAPS_OPTION key semantics literally: a role ABSENT from
	 * the option was never configured (it predates the screen, or another
	 * plugin registered it); a role PRESENT — even with an empty cap list —
	 * is a choice the owner made and typed.
	 *
	 * Permission_Engine needs the difference. Its space-scoped fallback exists
	 * for roles nobody ever mapped, and must not quietly reinstate a cap an
	 * owner unticked on purpose. "Never mapped" gets the fallback; "the owner
	 * said no" does not.
	 *
	 * @param int $user_id User to test.
	 * @return bool True when at least one of the user's roles is explicitly stored.
	 */
	public static function has_explicit_role_mapping( int $user_id ): bool {
		$stored = get_option( self::ROLE_CAPS_OPTION, array() );
		if ( ! is_array( $stored ) || ! $stored ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		foreach ( (array) $user->roles as $slug ) {
			if ( array_key_exists( (string) $slug, $stored ) ) {
				return true;
			}
		}

		return false;
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
		// In a minimal boot context (the WP-stub smoke test) the roles API
		// may not exist yet.
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		// First sync on a site that predates the editable mapping: snapshot
		// any live role whose Jetonomy caps differ from the ROLE_MAP default
		// (hand-granted custom roles included) into the option, so syncing
		// never changes a site's effective permissions out from under it.
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
			$seed     = array();
			foreach ( wp_roles()->role_objects as $slug => $role ) {
				if ( 'administrator' === $slug ) {
					continue;
				}
				$live = array_values( array_filter( self::all(), static fn( $cap ) => $role->has_cap( $cap ) ) );
				if ( empty( $live ) ) {
					continue;
				}
				$def = $defaults[ $slug ] ?? array();
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
			$want = isset( $targets[ $slug ] ) ? $targets[ $slug ] : array();
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
	 * Whether a user may create a space from the FRONT END.
	 *
	 * The single gate for the REST create route, the /new-space/ form, and the
	 * user-panel "Create space" link. Settings -> General -> "Who can create
	 * spaces" is the single control; there is no separate capability for it.
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
				$settings = get_option( 'jetonomy_settings', array() );
				$allowed  = isset( $settings['frontend_space_creation_roles'] )
					? array_filter( array_map( 'sanitize_key', (array) $settings['frontend_space_creation_roles'] ) )
					: array();

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
		$can = (bool) apply_filters( 'jetonomy_can_create_space_frontend', $can, $user_id );

		// A banned or silenced account may not start a space, whatever its role.
		return $can && null === Permission_Engine::restriction_error( $user_id, 'create_space' );
	}

	/**
	 * Human labels for every capability, for the mapping screen.
	 *
	 * @return array<string, string> cap => label.
	 */
	public static function labels(): array {
		// Owner terminology: the posts caps cover topics, and own/others edit and delete cover replies too.
		$topics  = \Jetonomy\jetonomy_label( 'topic', true, true );
		$content = sprintf( /* translators: 1: topic label, plural lowercase. 2: reply label, plural lowercase. */ __( '%1$s and %2$s', 'jetonomy' ), $topics, \Jetonomy\jetonomy_label( 'reply', true, true ) );

		return array(
			'jetonomy_read'                => __( 'Read the community', 'jetonomy' ),
			'jetonomy_create_posts'        => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Create %s', 'jetonomy' ), $topics ),
			'jetonomy_create_replies'      => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Create %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
			'jetonomy_edit_own_posts'      => sprintf( /* translators: %s: topic and reply labels, e.g. "topics and replies". */ __( 'Edit own %s', 'jetonomy' ), $content ),
			'jetonomy_delete_own_posts'    => sprintf( /* translators: %s: topic and reply labels, e.g. "topics and replies". */ __( 'Delete own %s', 'jetonomy' ), $content ),
			'jetonomy_vote'                => __( 'Vote', 'jetonomy' ),
			'jetonomy_flag'                => __( 'Report content', 'jetonomy' ),
			'jetonomy_join_spaces'         => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Join %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
			'jetonomy_upload_media'        => __( 'Upload media', 'jetonomy' ),
			'jetonomy_edit_others_posts'   => sprintf( /* translators: %s: topic and reply labels, e.g. "topics and replies". */ __( "Edit others' %s", 'jetonomy' ), $content ),
			'jetonomy_delete_others_posts' => sprintf( /* translators: %s: topic and reply labels, e.g. "topics and replies". */ __( "Delete others' %s", 'jetonomy' ), $content ),
			'jetonomy_moderate'            => __( 'Moderate (queue, flags, bans)', 'jetonomy' ),
			'jetonomy_manage_users'        => __( 'Manage community users', 'jetonomy' ),
			'jetonomy_move_posts'          => sprintf( /* translators: 1: topic label, plural lowercase. 2: space label, plural lowercase. */ __( 'Move %1$s between %2$s', 'jetonomy' ), $topics, \Jetonomy\space_label( true, true ) ),
			'jetonomy_close_posts'         => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Close %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
			'jetonomy_pin_posts'           => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Pin %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
			'jetonomy_manage_settings'     => __( 'Manage settings', 'jetonomy' ),
			'jetonomy_manage_categories'   => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Manage %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', true, true ) ),
			'jetonomy_manage_spaces'       => sprintf( /* translators: %s: the plural Space label, lowercase. */ __( 'Manage all %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
			'jetonomy_manage_badges'       => __( 'Manage badges', 'jetonomy' ),
			'jetonomy_view_analytics'      => __( 'View analytics', 'jetonomy' ),
			'jetonomy_manage_extensions'   => __( 'Manage extensions', 'jetonomy' ),
		);
	}

	/**
	 * Whether the user may upload media. Same rule the REST routes enforce,
	 * so templates hide controls a refused request would sit behind.
	 *
	 * @param int $user_id User ID; 0 = current user.
	 */
	public static function can_upload( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		foreach ( self::UPLOAD_CAPS as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a user may set an image URL (profile picture, space cover).
	 *
	 * Pointing at a new image is an upload, so it needs can_upload() - the same
	 * check the forms run before showing the control. Clearing the image, or
	 * re-sending the unchanged value with the rest of a form, is always allowed.
	 *
	 * @param string $new_url     Sanitized URL being saved.
	 * @param string $current_url URL stored now.
	 * @param int    $user_id     User making the change.
	 * @return bool
	 */
	public static function can_set_image( string $new_url, string $current_url, int $user_id ): bool {
		return '' === $new_url || $new_url === $current_url || self::can_upload( $user_id );
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
		// Cached 60s per user — /users/me runs on app boot and
		// screen mounts, and this walks the whole capability set through
		// user_can() each time. A whole-payload /users/me cache was rejected
		// by the ownership map (it would stack over the profile/user row
		// caches); this is the one genuinely uncached expensive part. Busted
		// on role change by the boot-level set_user_role hook (caps:{id}).
		$cached = \Jetonomy\Cache::get( "caps:{$user_id}" );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array();
		foreach ( array_merge( self::all(), array( 'manage_options' ) ) as $cap ) {
			$out[ $cap ] = user_can( $user_id, $cap );
		}

		\Jetonomy\Cache::set( "caps:{$user_id}", $out, 60 );
		return $out;
	}
}
