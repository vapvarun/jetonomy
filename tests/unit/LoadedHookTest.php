<?php
/**
 * jetonomy_loaded and jetonomy_pro_loaded: once per request, after boot, with the plugin object.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

class LoadedHookTest extends WP_UnitTestCase {

	public function test_jetonomy_loaded_fired_once_during_this_boot(): void {
		$this->assertSame( 1, did_action( 'jetonomy_loaded' ) );
	}

	public function test_it_fires_after_boot_with_the_main_object_and_not_again_when_init_is_re_entered(): void {
		global $wp_actions;
		$fired = $wp_actions['jetonomy_loaded'];
		$seen  = array();
		add_action(
			'jetonomy_loaded',
			static function ( $jetonomy ) use ( &$seen ): void {
				$seen[] = array( $jetonomy, class_exists( '\Jetonomy\Router', false ), (bool) has_action( 'init', array( \jetonomy(), 'maybe_seed_verification_reminder_defaults' ) ) );
			}
		);
		$wp_actions['jetonomy_loaded'] = 0; // As on a request whose boot has not finished yet.
		try {
			\jetonomy()->init();
			\jetonomy()->init(); // Re-entered: no second fire.
		} finally {
			$wp_actions['jetonomy_loaded'] = $fired;
		}
		$this->assertCount( 1, $seen );
		$this->assertSame( \jetonomy(), $seen[0][0], 'the main object' );
		$this->assertTrue( $seen[0][1] && $seen[0][2], 'after the router and the init hooks are in place' );
	}

	public function test_a_paused_boot_returns_before_the_class_that_fires_it_is_loaded(): void {
		$main  = (string) file_get_contents( JETONOMY_DIR . 'jetonomy.php' );
		$floor = strpos( $main, "version_compare( PHP_VERSION, '8.1', '<' )" );
		$this->assertNotFalse( $floor );
		$this->assertLessThan( strpos( $main, "includes/class-jetonomy.php'" ), strpos( $main, 'return;', $floor ) );
	}

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
