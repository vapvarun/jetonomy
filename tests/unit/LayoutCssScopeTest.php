<?php
/**
 * The Appearance -> Layout overrides must only reach the community app's own
 * wrappers. A bare `body.jt-page .container` also matched the theme header and
 * footer containers, so "Full Width" stretched the site header edge-to-edge on
 * Reign / BuddyX / BuddyX Pro, and "Hide sidebar" hid Reign's footer widgets.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Integrations\Layout_CSS;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Integrations\Layout_CSS
 */
class LayoutCssScopeTest extends WP_UnitTestCase {

	private function rules( array $settings ): string {
		$method = new \ReflectionMethod( Layout_CSS::class, 'build_rules' );
		$method->setAccessible( true );
		return (string) $method->invoke( new Layout_CSS(), $settings );
	}

	public function test_defaults_emit_nothing(): void {
		$this->assertSame( '', $this->rules( array() ) );
	}

	public function test_every_theme_selector_is_scoped_to_app_ancestors(): void {
		$css = $this->rules(
			array(
				'container_width'    => 'full',
				'sidebar_visibility' => 'hide',
			)
		);

		$this->assertNotSame( '', $css );
		// No unscoped theme class may appear: each one would also hit the header/footer.
		foreach ( array( '.container,', '.wrap,', '.widget-area,', '#secondary,', '.sidebar,' ) as $needle ) {
			$this->assertStringNotContainsString( 'body.jt-page ' . $needle, $css );
		}
		$this->assertStringContainsString( ':has(#jetonomy-app)', $css );
		$this->assertStringContainsString( 'body.jt-page :has(#jetonomy-app)>:is(#secondary,.widget-area,.sidebar,.sidebar-primary)', $css );
	}
}
