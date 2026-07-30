<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * Coverage for jetonomy_normalize_editor_html() (Basecamp 10138808747).
 *
 * The composer submits contenteditable innerHTML: Chromium/WebKit wrap each
 * Enter-separated block in a bare <div> (empty line = <div><br></div>),
 * Firefox-style editors separate blocks with <br><br>. wpautop() skips
 * block-level tags, so the divs reached storage and the CSS paragraph rhythm
 * (.jt-post-body p) never applied - consecutive paragraphs rendered with 0px
 * between them, and derived titles mushed paragraphs together with no space.
 *
 * The helper runs at write time (all four REST content sites) AND as a
 * display-time shim in jetonomy_format_content() for content stored by older
 * releases, so one implementation covers new and legacy rows.
 */
class NormalizeEditorHtmlTest extends WP_UnitTestCase {

	public function test_chromium_div_soup_becomes_paragraphs(): void {
		$in  = 'Alpha one.<div>Beta two.</div><div>Gamma three.</div>';
		$out = jetonomy_normalize_editor_html( $in );

		$this->assertSame( "<p>Alpha one.</p>\n<p>Beta two.</p>\n<p>Gamma three.</p>", $out );
	}

	public function test_editor_empty_line_collapses(): void {
		$out = jetonomy_normalize_editor_html( 'One.<div><br></div><div>Two.</div>' );

		$this->assertSame( "<p>One.</p>\n<p>Two.</p>", $out );
	}

	public function test_double_br_is_a_paragraph_break_single_br_is_kept(): void {
		$out = jetonomy_normalize_editor_html( 'One.<br><br>Two.<br>same paragraph' );

		$this->assertStringContainsString( '<p>One.</p>', $out );
		$this->assertStringContainsString( "<p>Two.<br />\nsame paragraph</p>", str_replace( '<br />same', "<br />\nsame", $out ) );
		$this->assertSame( 2, substr_count( $out, '<p>' ) );
	}

	public function test_clean_paragraph_content_is_untouched(): void {
		$in = "<p>Already clean.</p>\n<p>Second.</p>";

		$this->assertSame( $in, jetonomy_normalize_editor_html( $in ) );
	}

	public function test_plain_text_is_untouched(): void {
		// Textarea-mode storage: display-side wpautop owns this form.
		$in = "Plain one.\n\nPlain two.";

		$this->assertSame( $in, jetonomy_normalize_editor_html( $in ) );
	}

	public function test_attributed_divs_disable_the_div_transform(): void {
		// With an attributed <div> present a bare </div> is ambiguous, so the
		// content must pass through byte-identical - no torn embed wrappers.
		$in = '<div class="jt-embed">keep</div><div>bare</div>';

		$this->assertSame( $in, jetonomy_normalize_editor_html( $in ) );
	}

	public function test_nested_bare_divs_do_not_tear_markup(): void {
		// The card's suggested non-greedy regex broke on nesting; boundary
		// replacement must not.
		$out = jetonomy_normalize_editor_html( '<div>outer<div>inner</div></div>' );

		$this->assertSame( "<p>outer</p>\n<p>inner</p>", $out );
		$this->assertStringNotContainsString( '<div', $out );
	}

	public function test_display_shim_repairs_legacy_stored_rows(): void {
		// jetonomy_format_content() must emit <p> blocks for div-soup a prior
		// release stored, without any migration.
		$rendered = jetonomy_format_content( 'Legacy a.<div>Legacy b.</div>' );

		$this->assertStringContainsString( '<p>Legacy a.</p>', $rendered );
		$this->assertStringContainsString( '<p>Legacy b.</p>', $rendered );
		$this->assertStringNotContainsString( '<div>', $rendered );
	}
}
