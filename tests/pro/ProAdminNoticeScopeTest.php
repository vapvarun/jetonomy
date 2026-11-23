<?php
/**
 * Pro's admin notices stay on Jetonomy's own screens, and a valid licence shows none.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

/**
 * Owner rule: the licence is only for updates, so no renewal nag; Pro's notices
 * show only on Jetonomy's admin screens, never on the Dashboard or elsewhere.
 */
class ProAdminNoticeScopeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded.' );
		}
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( 'jetonomy-pro_license' );
		delete_option( 'jetonomy_pro_ai_outage' );
		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	private function output_on( string $screen, callable $render ): string {
		set_current_screen( $screen );
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_a_valid_licence_close_to_expiry_shows_no_notice(): void {
		if ( defined( 'JETONOMY_PRO_LICENSE_BYPASS' ) ) {
			$this->markTestSkipped( 'Licence bypass is on.' );
		}
		update_option(
			'jetonomy-pro_license',
			(object) array(
				'license' => 'valid',
				'expires' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);
		$this->assertTrue( \Jetonomy_Pro\License\License::is_valid() );
		$this->assertSame( '', $this->output_on( 'toplevel_page_jetonomy', array( \Jetonomy_Pro::instance(), 'render_license_notices' ) ) );
	}

	public function test_updates_off_shows_on_jetonomy_screens_only(): void {
		if ( defined( 'JETONOMY_PRO_LICENSE_BYPASS' ) ) {
			$this->markTestSkipped( 'Licence bypass is on.' );
		}
		$render = array( \Jetonomy_Pro::instance(), 'render_license_notices' );
		$this->assertStringContainsString( 'Updates are off.', $this->output_on( 'toplevel_page_jetonomy', $render ) );
		$this->assertSame( '', $this->output_on( 'dashboard', $render ) );
	}

	public function test_the_ai_outage_notice_shows_on_jetonomy_screens_only(): void {
		$ai = \Jetonomy_Pro::instance()->get_extensions()['ai'] ?? null;
		if ( ! $ai ) {
			$this->markTestSkipped( 'AI extension not loaded.' );
		}
		update_option(
			'jetonomy_pro_ai_outage',
			array(
				'feature' => 'spam',
				'time'    => time() - 60,
				'message' => '',
			)
		);
		$render = array( $ai, 'render_outage_notice' );
		$this->assertSame( '', $this->output_on( 'dashboard', $render ), 'not on the Dashboard' );
		$this->assertStringContainsString( 'not reaching any provider', $this->output_on( 'toplevel_page_jetonomy', $render ) );
	}
}
