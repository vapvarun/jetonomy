<?php
/**
 * Stylesheet guarantees a browser check found missing:
 * - Links inside post and reply bodies are underlined, not told apart by
 *   colour alone (WCAG 1.4.1); `.jt-app a` strips every app link to text colour.
 * - On phones the app clears the fixed tab bar plus the iOS safe-area inset, so
 *   a composer's Post button is never under the bar after scrolling to the end.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

class MemberContentCssTest extends WP_UnitTestCase {

	private function css(): string {
		return (string) file_get_contents( JETONOMY_DIR . 'assets/css/jetonomy.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_links_in_member_content_are_underlined(): void {
		$this->assertMatchesRegularExpression(
			'/\.jt-app :is\(\.jt-post-body, \.jt-reply-body\) a:not\([^)]*\) \{[^}]*text-decoration: underline;/',
			$this->css()
		);
	}

	public function test_phone_layout_clears_the_tab_bar_and_safe_area(): void {
		$css = $this->css();
		preg_match_all( '/--jt-tab-bar-clearance: ([^;]+);/', $css, $m );
		$this->assertCount( 1, $m[1], 'one owner for the tab-bar clearance' );
		$this->assertStringContainsString( 'env(safe-area-inset-bottom', $m[1][0] );
		$this->assertStringNotContainsString( 'px', str_replace( '0px', '', $m[1][0] ), 'tokens, not a fixed pixel height' );
		// Padding alone only clears the bar at the end of the page: a Post button
		// mid-thread, reached by Tab or scrollIntoView, stopped under the tabs.
		$this->assertMatchesRegularExpression( '/:root:has\(\.jt-mobile-tabs\) \{[^}]*scroll-padding-bottom: var\(--jt-tab-bar-clearance\)/', $css );
		$this->assertStringContainsString( '.jt-app { padding-bottom: var(--jt-tab-bar-clearance, 0px); }', $css );
	}
}
