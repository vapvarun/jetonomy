<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * Reader-side rendering of pre-1.9.3 markdown images (Basecamp 10199514340).
 *
 * The mobile app inserted `![alt](url)` into the body until 1.9.3. Nothing on
 * the web side understood the syntax, so an image attached in the app showed
 * there and rendered as a line of raw text on the website. The app now writes
 * `<img>`, but that only fixes NEW content - every row already stored keeps the
 * old syntax, so the reader learns to render it instead of the data being
 * rewritten.
 *
 * Two stored shapes matter, because the save-time autolinker rewrote the URL
 * inside the parens on the way in. Both must produce the same image.
 *
 * The rejection cases are the point of the guard: markdown is member-authored,
 * so the URL is untrusted input on a path that emits a tag.
 */
class MarkdownImageRenderTest extends WP_UnitTestCase {

	private const URL = 'http://example.org/wp-content/uploads/2026/08/photo.png';

	public function test_renders_a_bare_markdown_image(): void {
		$out = jetonomy_render_markdown_images( 'Before ![My photo](' . self::URL . ') after' );

		$this->assertStringContainsString( '<img src="' . self::URL . '"', $out );
		$this->assertStringContainsString( 'alt="My photo"', $out );
		$this->assertStringNotContainsString( '![', $out );
	}

	/**
	 * The shape actually found in the database: the URL inside the parens was
	 * autolinked at save time, so the pattern arrives wrapped in an anchor.
	 */
	public function test_renders_the_autolinked_shape_stored_in_the_database(): void {
		$stored = 'Before ![My photo](<a href="' . self::URL . '" target="_blank" rel="noopener">' . self::URL . '</a>) after';

		$out = jetonomy_render_markdown_images( $stored );

		$this->assertStringContainsString( '<img src="' . self::URL . '"', $out );
		$this->assertStringNotContainsString( '![', $out );
		$this->assertStringNotContainsString( '<a ', $out, 'the wrapping anchor must be consumed, not left beside the image' );
	}

	public function test_both_stored_shapes_render_identically(): void {
		$bare   = jetonomy_render_markdown_images( '![Shot](' . self::URL . ')' );
		$linked = jetonomy_render_markdown_images(
			'![Shot](<a href="' . self::URL . '" target="_blank" rel="noopener">' . self::URL . '</a>)'
		);

		$this->assertSame( $bare, $linked );
	}

	public function test_renders_every_image_in_the_body(): void {
		$out = jetonomy_render_markdown_images(
			'![one](http://example.org/1.png) middle ![two](http://example.org/2.png)'
		);

		$this->assertSame( 2, substr_count( $out, '<img ' ) );
		$this->assertStringContainsString( 'middle', $out );
	}

	public function test_escapes_the_alt_text(): void {
		$out = jetonomy_render_markdown_images( '![He said "hi" & <b>bye</b>](' . self::URL . ')' );

		$this->assertStringContainsString( '&quot;hi&quot;', $out );
		$this->assertStringNotContainsString( '<b>', $out );
	}

	/**
	 * @dataProvider hostile_urls
	 */
	public function test_leaves_unsafe_urls_as_plain_text( string $url ): void {
		$in  = '![x](' . $url . ')';
		$out = jetonomy_render_markdown_images( $in );

		$this->assertSame( $in, $out, 'an unsafe URL must stay inert text, never become a tag' );
		$this->assertStringNotContainsString( '<img', $out );
	}

	public function hostile_urls(): array {
		return array(
			'javascript'  => array( 'javascript:alert(1)' ),
			'data uri'    => array( 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=' ),
			'vbscript'    => array( 'vbscript:msgbox(1)' ),
			'protocol-relative-to-nothing' => array( 'ftp://example.org/x.png' ),
		);
	}

	public function test_leaves_ordinary_markdown_links_alone(): void {
		$in = 'This [is a link](http://example.org/page) not an image';

		$this->assertSame( $in, jetonomy_render_markdown_images( $in ) );
	}

	public function test_is_a_no_op_for_content_without_markdown(): void {
		$in = '<p>Plain body with <strong>html</strong> and an <img src="' . self::URL . '" alt="" /> already.</p>';

		$this->assertSame( $in, jetonomy_render_markdown_images( $in ) );
	}

	/**
	 * The display pipeline is the thing customers actually see, so assert the
	 * wiring too - a correct helper that nothing calls fixes nothing.
	 */
	public function test_the_display_formatter_applies_it(): void {
		$out = jetonomy_format_content( 'Body ![My photo](' . self::URL . ')' );

		$this->assertStringContainsString( '<img src="' . self::URL . '"', $out );
		$this->assertStringNotContainsString( '![', $out );
	}
}
