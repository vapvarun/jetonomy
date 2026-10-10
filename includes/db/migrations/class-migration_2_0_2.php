<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * 2.0.2: role capability mapping repairs.
 *
 * - Third-party roles (Customer, Student...) stored with an empty cap list
 *   by a Permissions-tab save are unmapped again, so their members get the
 *   space-membership fallback back (Basecamp 10392987680).
 * - Uploads are now gated on jetonomy_upload_media / upload_files alone
 *   (Basecamp 10380388502). Until now anyone who could post could upload,
 *   so every role that can post is granted jetonomy_upload_media here and
 *   no site loses uploads on update. Unticking it now actually stops them.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

use Jetonomy\Permissions\Capabilities;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_2 {

	private const UPLOAD = 'jetonomy_upload_media';
	private const POSTS  = array( 'jetonomy_create_posts', 'jetonomy_create_replies' );

	public function up(): void {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}
		require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';

		$stored = get_option( Capabilities::ROLE_CAPS_OPTION, false );

		if ( is_array( $stored ) ) {
			$defaults = Capabilities::default_map();
			foreach ( $stored as $role => $caps ) {
				$caps = (array) $caps;
				if ( ! $caps && ! isset( $defaults[ $role ] ) ) {
					unset( $stored[ $role ] );
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
			foreach ( wp_roles()->role_objects as $role ) {
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
}
