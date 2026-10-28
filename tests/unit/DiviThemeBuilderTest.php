<?php
namespace {
	if ( ! function_exists( 'et_theme_builder_get_template_layouts' ) ) {
		/** Stand-in for Divi being active. */
		function et_theme_builder_get_template_layouts() {
			return array();
		}
	}
}

namespace Jetonomy\Tests\Unit {

use WP_UnitTestCase;
use Jetonomy\Integrations\Divi_Theme_Builder;

/**
 * Divi applies a Theme Builder template, and prints its layouts' styles, only
 * when it reads the request as singular. Community routes are not, so the
 * integration opens short windows where they are. The full behaviour needs
 * Divi (verified on Divi 5.16 and Divi Builder 4.27.7); this pins the windows.
 */
class DiviThemeBuilderTest extends WP_UnitTestCase {

	public function tear_down(): void {
		set_query_var( 'jetonomy_route', '' );
		parent::tear_down();
	}

	public function test_windows_nest_and_restore_on_a_community_route(): void {
		global $wp_query;
		$divi = new Divi_Theme_Builder();
		set_query_var( 'jetonomy_route', 'home' );
		$wp_query->is_singular = false;

		$this->assertSame( 'x', $divi->open_filter( 'x' ), 'A filter value passes through.' );
		$this->assertTrue( is_singular() );
		$divi->open();
		$divi->close();
		$this->assertTrue( is_singular(), 'An inner window does not close the outer one.' );
		$divi->close();
		$this->assertFalse( is_singular() );
		$divi->close();
		$this->assertFalse( is_singular(), 'An unmatched close changes nothing.' );

		$this->assertSame( 0, $divi->no_post_id( 534 ), 'A layout id is never taken as the page post.' );
	}

	public function test_nothing_changes_off_community_routes(): void {
		global $wp_query;
		$divi                  = new Divi_Theme_Builder();
		$wp_query->is_singular = false;

		$divi->open();
		$this->assertFalse( is_singular() );
		$divi->close();
		$this->assertSame( 534, $divi->no_post_id( 534 ) );
	}

	public function test_every_layout_render_runs_inside_a_window(): void {
		$divi = new Divi_Theme_Builder();
		$this->assertNotFalse( has_action( 'et_theme_builder_after_layout_opening_wrappers', array( $divi, 'open' ) ) );
		$this->assertNotFalse( has_action( 'et_theme_builder_before_layout_closing_wrappers', array( $divi, 'close' ) ) );
		$this->assertSame( 9, has_action( 'wp', array( $divi, 'setup_module_styles' ) ), 'Before Divi sets up its stylesheet at 10.' );
	}
}
}
