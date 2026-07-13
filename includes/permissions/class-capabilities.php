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
