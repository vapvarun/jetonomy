<?php
/**
 * Wbcom stack companion installer.
 *
 * Installs a companion plugin by reusing the EDD delivery channel the companions
 * already speak: POST the store with `edd_action=get_version` + item_id + key,
 * take the signed package URL it returns, and hand it to WP core's
 * Plugin_Upgrader. Free companions install with the baked-in free distribution
 * key (unlimited, no expiry); Pro requires the customer's own valid license.
 *
 * Jetonomy's job ends at activation — the companion's own bundled SDK then
 * manages its updates. This never manages a companion's lifecycle after install.
 *
 * Mirrors Learnomy's Companion_Installer so the pattern stays consistent across
 * the Wbcom stack.
 *
 * @package Jetonomy\Integrations
 */

namespace Jetonomy\Integrations;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Companion_Installer {

	private const STORE_URL = 'https://wbcomdesigns.com';
	private const TIMEOUT   = 20;

	/**
	 * Install (and activate) a companion.
	 *
	 * @param string $slug    Companion slug.
	 * @param string $tier    'free' | 'pro'.
	 * @param string $license Customer license key (Pro only).
	 * @return true|WP_Error True on success (installed + active), WP_Error otherwise.
	 */
	public static function install( string $slug, string $tier = 'free', string $license = '' ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'jetonomy_cap', __( 'You do not have permission to install plugins.', 'jetonomy' ) );
		}

		$entry = Companion_Registry::get( $slug );
		if ( null === $entry ) {
			return new WP_Error( 'jetonomy_unknown_companion', __( 'Unknown integration.', 'jetonomy' ) );
		}

		// Already live — nothing to do.
		if ( Companion_Registry::is_active( $slug ) ) {
			return true;
		}

		$tier    = 'pro' === $tier ? 'pro' : 'free';
		$config  = $entry[ $tier ] ?? array();
		$item_id = (int) ( $config['item_id'] ?? 0 );
		if ( $item_id <= 0 ) {
			return new WP_Error( 'jetonomy_no_item', __( 'This integration cannot be installed automatically. Visit the store.', 'jetonomy' ) );
		}

		// Free uses the baked-in distribution key; Pro requires the customer's.
		$key = 'pro' === $tier ? trim( $license ) : (string) ( $config['key'] ?? '' );
		if ( '' === $key ) {
			return new WP_Error( 'jetonomy_no_license', __( 'A license key is required for this download.', 'jetonomy' ) );
		}

		// If the plugin is already on disk (installed_inactive), just activate it.
		$basename = (string) ( $config['basename'] ?? ( $entry['free']['basename'] ?? '' ) );
		if ( '' !== $basename && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $basename ) ) {
			return self::activate( $basename );
		}

		// EDD Software Licensing only authorizes package_download once the license
		// is activated for this domain. Activate first, and surface the store's
		// real reason if it refuses so the failure is diagnosable.
		$activation = self::activate_license( $item_id, $key );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}

		$package = self::resolve_package_url( $item_id, $key, $tier );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$installed = self::install_package( $package );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$activate_target = '' !== $basename ? $basename : (string) $installed;
		return self::activate( $activate_target );
	}

	/**
	 * Activate the license for this domain (required before EDD authorizes the
	 * package download).
	 *
	 * @param int    $item_id Store product id.
	 * @param string $key     License / free distribution key.
	 * @return true|WP_Error
	 */
	private static function activate_license( int $item_id, string $key ) {
		$response = wp_remote_post(
			self::STORE_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action'  => 'activate_license',
					'item_id'     => $item_id,
					'license'     => $key,
					'url'         => home_url(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jetonomy_store_unreachable', __( 'Could not reach the store to activate the license. Please try again.', 'jetonomy' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'jetonomy_store_bad_response', __( 'The store returned an unexpected response while activating the license.', 'jetonomy' ) );
		}

		$status = (string) ( $body['license'] ?? '' );
		if ( in_array( $status, array( 'valid', 'active' ), true ) ) {
			return true;
		}
		if ( 'invalid' === $status && ! empty( $body['success'] ) ) {
			return true;
		}

		$reason = (string) ( $body['error'] ?? ( '' !== $status ? $status : 'unknown' ) );
		return new WP_Error(
			'jetonomy_license_activation_failed',
			sprintf(
				/* translators: %s: the store's activation error reason. */
				__( 'The store would not activate this free license for your site (reason: %s). This is a store-side license configuration issue, not a site error.', 'jetonomy' ),
				$reason
			)
		);
	}

	/**
	 * Ask the store for the signed package URL for an item.
	 *
	 * @param int    $item_id Store product id.
	 * @param string $key     License / free distribution key.
	 * @param string $tier    'free' | 'pro'.
	 * @return string|WP_Error Package URL, or WP_Error.
	 */
	private static function resolve_package_url( int $item_id, string $key, string $tier ) {
		$response = wp_remote_post(
			self::STORE_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action' => 'get_version',
					'item_id'    => $item_id,
					'license'    => $key,
					'url'        => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jetonomy_store_unreachable', __( 'Could not reach the store. Please try again.', 'jetonomy' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'jetonomy_store_bad_response', __( 'The store returned an unexpected response.', 'jetonomy' ) );
		}

		// Pro must present a valid license — never auto-install on an
		// invalid/expired key; the UI shows the store link instead.
		if ( 'pro' === $tier && isset( $body['license'] ) && 'valid' !== $body['license'] ) {
			return new WP_Error( 'jetonomy_license_invalid', __( 'That license is not valid for this product.', 'jetonomy' ) );
		}

		$package = (string) ( $body['download_link'] ?? ( $body['package'] ?? '' ) );
		if ( '' === $package ) {
			return new WP_Error( 'jetonomy_no_package', __( 'The store did not return a download for this plugin.', 'jetonomy' ) );
		}

		return $package;
	}

	/**
	 * Download + unpack a plugin zip via WP core's Plugin_Upgrader.
	 *
	 * @param string $package Signed package URL.
	 * @return string|WP_Error Installed plugin basename/destination, or WP_Error.
	 */
	private static function install_package( string $package ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$creds = request_filesystem_credentials( '', '', false, '', null );
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			return new WP_Error( 'jetonomy_fs', __( 'WordPress needs filesystem access to install plugins. Configure direct file access or install from the Plugins screen.', 'jetonomy' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			// WP's generic "download_failed" hides WHY. Probe the package URL once
			// so the message carries the store's real reason (e.g. a 401 "Invalid
			// license supplied"), which is what makes this diagnosable.
			if ( 'download_failed' === $result->get_error_code() ) {
				$probe  = wp_remote_get( $package, array( 'timeout' => self::TIMEOUT ) );
				$code   = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
				$reason = is_wp_error( $probe ) ? $probe->get_error_message() : trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $probe ) ) );
				if ( $code >= 400 ) {
					return new WP_Error(
						'jetonomy_download_rejected',
						sprintf(
							/* translators: 1: HTTP status, 2: store reason text. */
							__( 'The store rejected the download (HTTP %1$d: %2$s). This is a store-side license/entitlement issue.', 'jetonomy' ),
							$code,
							'' !== $reason ? mb_substr( $reason, 0, 120 ) : __( 'no reason given', 'jetonomy' )
						)
					);
				}
			}
			return $result;
		}
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			if ( $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'jetonomy_install_failed', __( 'The plugin could not be installed.', 'jetonomy' ) );
		}

		return (string) $upgrader->plugin_info();
	}

	/**
	 * Activate an installed plugin by basename.
	 *
	 * @param string $basename e.g. "learnomy/learnomy.php".
	 * @return true|WP_Error
	 */
	private static function activate( string $basename ) {
		if ( '' === $basename ) {
			return new WP_Error( 'jetonomy_activate', __( 'Installed, but the plugin could not be activated automatically. Activate it from the Plugins screen.', 'jetonomy' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$activated = activate_plugin( $basename );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}
		return true;
	}
}
