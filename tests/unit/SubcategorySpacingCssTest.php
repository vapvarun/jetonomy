<?php
/**
 * The category's space grid sits 16px under its sub-category block, collapsed or open.
 *
 * `.jt-cat-subs + * { margin-top }` was lighter than `.jt-app .jt-space-grid { margin: 0 }`, so the grid touched
 * the chips when the block was collapsed and sat 12px under the open panel.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Sub-category spacing rule.
 */
class SubcategorySpacingCssTest extends WP_UnitTestCase {

	/**
	 * The rule is in the source and in both generated files, with the weight to beat the grid's reset.
	 */
	public function test_the_grid_rule_is_in_every_stylesheet(): void {
		$files = array(
			'jetonomy.css'         => '/\.jt-app \.jt-cat-subs \+ \.jt-space-grid \{ margin-top: var\(--jt-space-4\); \}/',
			'jetonomy.min.css'     => '/\.jt-app \.jt-cat-subs\+\.jt-space-grid\{margin-top:var\(--jt-space-4\)\}/',
			'jetonomy-rtl.css'     => '/\.jt-app \.jt-cat-subs \+ \.jt-space-grid \{ margin-top: var\(--jt-space-4\); \}/',
			'jetonomy-rtl.min.css' => '/\.jt-app \.jt-cat-subs\+\.jt-space-grid\{margin-top:var\(--jt-space-4\)\}/',
		);

		foreach ( $files as $file => $pattern ) {
			$css = (string) file_get_contents( JETONOMY_DIR . 'assets/css/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertMatchesRegularExpression( $pattern, $css, "$file carries the grid spacing rule." );
		}
	}

	/**
	 * Anything else that follows the block takes the same step.
	 */
	public function test_whatever_follows_the_block_takes_the_same_step(): void {
		$css = (string) file_get_contents( JETONOMY_DIR . 'assets/css/jetonomy.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertMatchesRegularExpression( '/\.jt-cat-subs \+ \* \{ margin-top: var\(--jt-space-4\); \}/', $css );
	}
}
