<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

class AfterContentAllowedHtmlTest extends WP_UnitTestCase {

	public function test_helper_exists_and_permits_card_markup(): void {
		$this->assertTrue( function_exists( 'jetonomy_after_content_allowed_html' ) );
		$allowed = jetonomy_after_content_allowed_html();

		// PDF card <button data-jt-pdf-url> must survive kses.
		$this->assertArrayHasKey( 'button', $allowed );
		$this->assertArrayHasKey( 'data-jt-pdf-url', $allowed['button'] );
		// Download chip <a download> must survive.
		$this->assertArrayHasKey( 'download', $allowed['a'] );
		// Icon <svg> must survive.
		$this->assertArrayHasKey( 'svg', $allowed );
		// Poll <input> (existing consumer) must still survive.
		$this->assertArrayHasKey( 'input', $allowed );
	}

	public function test_kses_keeps_pdf_button(): void {
		$html = '<button type="button" class="jt-attach" data-jt-pdf-url="/x.pdf" aria-label="Open">P</button>';
		$out  = wp_kses( $html, jetonomy_after_content_allowed_html() );
		$this->assertStringContainsString( 'data-jt-pdf-url="/x.pdf"', $out );
	}

	public function test_kses_keeps_poll_input_no_regression(): void {
		$html = '<input type="radio" name="poll" value="1" data-wp-on--change="a" />';
		$out  = wp_kses( $html, jetonomy_after_content_allowed_html() );
		$this->assertStringContainsString( 'data-wp-on--change', $out );
		$this->assertStringContainsString( 'type="radio"', $out );
	}
}
