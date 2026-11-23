<?php
/**
 * Deleting Pro with data removal on clears its licence SDK cache, and only its own.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

/**
 * Pro's uninstall.php declares functions, so this is the only test that includes it.
 */
class ProUninstallLicenceCacheTest extends WP_UnitTestCase {

	public function test_remove_data_deletes_our_sdk_cache_and_keeps_others(): void {
		if ( ! defined( 'JETONOMY_PRO_DIR' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded.' );
		}
		update_option( 'jetonomy_settings', array( 'remove_data_on_uninstall' => 1 ) );
		update_option( 'jetonomy-pro_license_key', 'probe-pro-key' );
		$ours = 'edd_sl_' . md5( (string) wp_json_encode( array( basename( untrailingslashit( JETONOMY_PRO_DIR ) ), 'probe-pro-key', 0 ) ) );
		update_option( $ours, array( 'timeout' => time() + HOUR_IN_SECONDS ) );
		update_option( 'edd_sl_' . md5( 'another-plugin' ), array( 'timeout' => 1 ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'jetonomy-pro/jetonomy-pro.php' );
		}
		// Pro's uninstall drops jt_pro_ tables; the test case turns DROP TABLE into DROP TEMPORARY TABLE.
		include JETONOMY_PRO_DIR . 'uninstall.php';

		$this->assertFalse( get_option( $ours ), "Pro's licence SDK update cache removed" );
		$this->assertFalse( get_option( 'jetonomy-pro_license_key' ), 'licence key removed' );
		$this->assertNotFalse( get_option( 'edd_sl_' . md5( 'another-plugin' ) ), "another plugin's SDK cache kept" );
	}
}
