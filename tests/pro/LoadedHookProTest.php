<?php
/**
 * jetonomy_pro_loaded: once per request, after Free, and only past Pro's boot guards.
 *
 * Lives in tests/pro/ because it needs Jetonomy Pro loaded; the free suite skips it.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class LoadedHookProTest extends WP_UnitTestCase {

	public function test_jetonomy_pro_loaded_fires_once_after_free_and_only_past_the_boot_guards(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded.' );
		}
		$this->assertSame( 1, did_action( 'jetonomy_pro_loaded' ) );
		$pro  = (string) file_get_contents( JETONOMY_PRO_DIR . 'jetonomy-pro.php' );
		$fire = strpos( $pro, "do_action( 'jetonomy_pro_loaded'" );
		$this->assertNotFalse( $fire );
		// Both refusals (Jetonomy missing, Jetonomy too old) return before it, and the extensions load before it.
		$this->assertLessThan( $fire, strpos( $pro, 'free_version_satisfied()' ) );
		$this->assertLessThan( $fire, strrpos( substr( $pro, 0, $fire ), 'return;' ) );
		$this->assertLessThan( $fire, strpos( $pro, 'Jetonomy_Pro::instance();' ) );
	}
}
