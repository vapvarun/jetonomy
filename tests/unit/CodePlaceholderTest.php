<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * The editor's Inline Code button opens a code run on a zero-width placeholder so the caret can sit inside it.
 * The placeholder must never reach stored content.
 */
class CodePlaceholderTest extends WP_UnitTestCase {

	private const Z = "\u{200B}";

	public function test_the_placeholder_is_removed_from_a_run_the_member_typed_into(): void {
		$html = '<p>Use: <code>' . self::Z . 'npm install</code>' . self::Z . ' and run it</p>';
		$this->assertSame( '<p>Use: <code>npm install</code> and run it</p>', jetonomy_sanitize_editor_content( $html ) );
	}

	public function test_a_run_the_member_never_typed_into_is_dropped(): void {
		$html = '<p>a <code>' . self::Z . '</code>b</p>';
		$this->assertSame( '<p>a b</p>', jetonomy_sanitize_editor_content( $html ) );
	}

	/**
	 * Negative control: pins behaviour that already worked, so it passes before and after the change.
	 *
	 * @covers-existing
	 */
	public function test_content_without_a_placeholder_is_unchanged(): void {
		$html = '<p>Run <code>npm install</code> first.</p>';
		$this->assertSame( wp_kses_post( $html ), jetonomy_sanitize_editor_content( $html ) );
	}
}
