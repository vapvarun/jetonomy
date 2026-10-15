<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * 2.0.2: role capability mapping repairs.
 *
 * - Uploads are now gated on jetonomy_upload_media alone (site admins always keep it).
 *   Until now anyone who could post could upload,
 *   so roles that can post are granted jetonomy_upload_media here and no
 *   site loses uploads on update. Contributor, Author and Editor shipped
 *   with the cap ticked, so a stored row without it is the owner's own
 *   choice and is left alone. Stored rows are never widened otherwise.
 * - Space bans stored without a space (the wp-admin Users dialog wrote them)
 *   never applied anywhere; they are removed so the Banned list stops
 *   showing bans that do nothing.
 * - The jetonomy_create_spaces capability is retired: Settings -> "Who can
 *   create spaces" is the only control and nothing read the cap. Before it is
 *   removed from every role, a site that never saved that setting gets it
 *   seeded from the roles holding the cap, so nobody loses space creation.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

use Jetonomy\Permissions\Capabilities;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_2 {

	private const UPLOAD                = 'jetonomy_upload_media';
	private const RETIRED_CREATE_SPACES = 'jetonomy_create_spaces';
	private const POSTS                 = array( 'jetonomy_create_posts', 'jetonomy_create_replies' );

	/** Roles whose pre-2.0.2 default already included the upload cap. */
	private const HAD_UPLOAD_DEFAULT = array( 'contributor', 'author', 'editor' );

	public function up(): void {
		global $wpdb;
		$restrictions = $wpdb->prefix . 'jt_restrictions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$restrictions} WHERE type = 'space_ban' AND ( space_id IS NULL OR space_id = 0 )" );

		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}
		require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';

		self::retire_create_spaces_cap();

		$stored = get_option( Capabilities::ROLE_CAPS_OPTION, false );

		if ( is_array( $stored ) ) {
			foreach ( $stored as $role => $caps ) {
				$caps = (array) $caps;
				if ( in_array( $role, self::HAD_UPLOAD_DEFAULT, true ) ) {
					continue;
				}
				if ( array_intersect( self::POSTS, $caps ) && ! in_array( self::UPLOAD, $caps, true ) ) {
					$caps[]          = self::UPLOAD;
					$stored[ $role ] = array_values( $caps );
				}
			}
			update_option( Capabilities::ROLE_CAPS_OPTION, $stored, false );
		} else {
			// Never-seeded site: register() is about to snapshot the live roles.
			// Grant on the live role first so that snapshot keeps uploads.
			foreach ( wp_roles()->role_objects as $slug => $role ) {
				if ( in_array( $slug, self::HAD_UPLOAD_DEFAULT, true ) ) {
					continue;
				}
				foreach ( self::POSTS as $cap ) {
					if ( $role->has_cap( $cap ) ) {
						$role->add_cap( self::UPLOAD );
						break;
					}
				}
			}
		}

		Capabilities::register();
	}

	/**
	 * Seed "Who can create spaces" from the cap holders if never saved, then
	 * strip the retired cap from every role and the stored mapping.
	 */
	private static function retire_create_spaces_cap(): void {
		$settings = get_option( 'jetonomy_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$roles    = wp_roles()->role_objects;

		if ( ! array_key_exists( 'frontend_space_creation_roles', $settings ) ) {
			$settings['frontend_space_creation_roles'] = array_keys(
				array_filter(
					$roles,
					static fn( $role ) => $role->has_cap( self::RETIRED_CREATE_SPACES ) || $role->has_cap( 'manage_options' )
				)
			);
			update_option( 'jetonomy_settings', $settings );
		}

		foreach ( $roles as $role ) {
			if ( $role->has_cap( self::RETIRED_CREATE_SPACES ) ) {
				$role->remove_cap( self::RETIRED_CREATE_SPACES );
			}
		}

		$stored = get_option( Capabilities::ROLE_CAPS_OPTION, false );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $role => $caps ) {
				$stored[ $role ] = array_values( array_diff( (array) $caps, array( self::RETIRED_CREATE_SPACES ) ) );
			}
			update_option( Capabilities::ROLE_CAPS_OPTION, $stored, false );
		}
	}
}
