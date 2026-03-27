<?php
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
	 * Remove all Jetonomy capabilities from all mapped roles.
	 *
	 * Used during plugin uninstall or deactivation.
	 */
	public static function unregister(): void {
		$all_caps = array_unique( array_merge( ...array_values( self::ROLE_MAP ) ) );
		foreach ( array_keys( self::ROLE_MAP ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Return a flat, deduplicated list of all Jetonomy capabilities.
	 *
	 * @return string[]
	 */
	public static function get_all(): array {
		return array_unique( array_merge( ...array_values( self::ROLE_MAP ) ) );
	}
}
