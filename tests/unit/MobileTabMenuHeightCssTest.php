<?php
/**
 * The phone bar's Me menu opens upward, so it is capped to the screen and scrolls.
 *
 * `.jt-mobile-tab-menu` had no height limit (only the wide "Start a topic in..." sheet did), so on a 390x320 screen
 * its top edge was 52px above the viewport and the first items could not be reached.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile tab menu height rule.
 */
class MobileTabMenuHeightCssTest extends WP_UnitTestCase {

	/**
	 * The base rule limits the height (vh first, dvh over it) and scrolls, in the source and in every built file.
	 */
	public function test_the_base_menu_rule_is_capped_in_every_stylesheet(): void {
		foreach ( array( 'jetonomy.css', 'jetonomy-rtl.css', 'jetonomy.min.css', 'jetonomy-rtl.min.css' ) as $file ) {
			$css = (string) file_get_contents( JETONOMY_DIR . 'assets/css/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
			$this->assertSame( 1, preg_match( '/\.jt-mobile-tab-menu\s*\{([^}]*)\}/', $css, $rule ), "$file has the base rule." );
			$this->assertMatchesRegularExpression( '/max-block-size:\s*60vh;\s*max-block-size:\s*60dvh/', $rule[1], "$file caps the height, with a vh fallback." );
			$this->assertMatchesRegularExpression( '/overflow-y:\s*auto/', $rule[1], "$file scrolls it." );
		}
	}

	/**
	 * The wide sheet inherits the cap from the base rule rather than carrying its own copy.
	 */
	public function test_the_wide_sheet_has_no_second_copy_of_the_limit(): void {
		$css = (string) file_get_contents( JETONOMY_DIR . 'assets/css/jetonomy.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file

		$this->assertSame( 1, preg_match( '/\.jt-mobile-tab-menu--wide\s*\{([^}]*)\}/', $css, $rule ) );
		$this->assertStringNotContainsString( 'max-block-size', $rule[1] );
	}
}
