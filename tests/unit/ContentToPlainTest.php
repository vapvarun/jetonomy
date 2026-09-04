<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * Coverage for jetonomy_content_to_plain() (Basecamp 10207964048).
 *
 * content_plain used to be a bare `wp_strip_all_tags( $content )` copied across
 * twenty-three call sites, and a bare strip gets plain text wrong twice: it
 * leaves entities encoded ("&amp;" reaches the member as five literal
 * characters) and it welds block boundaries together ("link.</p><blockquote>"
 * becomes "link.nested"). Both were visible in the app's Quote action, feed
 * excerpts, notification text and search snippets, since all of them read this
 * one column.
 *
 * The ordering is the part worth protecting: block closers must become
 * newlines BEFORE the strip (afterwards there is no boundary left), and
 * entities must be decoded AFTER it (decoding first turns a member's stored
 * "&lt;script&gt;" into a real tag for strip_tags to eat).
 */
class ContentToPlainTest extends WP_UnitTestCase {

	/** The exact body from the card's failing QA run. */
	public function test_entities_are_decoded_and_blocks_are_separated(): void {
		$in = '<p>It\'s a <strong>bold</strong> claim &amp; a &quot;quoted&quot; one. '
			. 'See <a href="https://example.com">this link</a>.</p><blockquote>nested quote</blockquote>';

		$this->assertSame(
			"It's a bold claim & a \"quoted\" one. See this link.\nnested quote",
			jetonomy_content_to_plain( $in )
		);
	}

	public function test_paragraphs_and_breaks_become_newlines(): void {
		$this->assertSame( "First para.\nSecond para.", jetonomy_content_to_plain( '<p>First para.</p><p>Second para.</p>' ) );
		$this->assertSame( "line one\nline two", jetonomy_content_to_plain( 'line one<br />line two' ) );
		$this->assertSame( "one\ntwo", jetonomy_content_to_plain( '<ul><li>one</li><li>two</li></ul>' ) );
	}

	/**
	 * Decode AFTER the strip, never before. A member who wrote about a
	 * <script> tag stored it as &lt;script&gt; and must read it back; decoding
	 * first would hand strip_tags a real tag and silently delete their words.
	 */
	public function test_escaped_markup_survives_but_real_markup_does_not(): void {
		$this->assertSame( 'Use <script> carefully', jetonomy_content_to_plain( '<p>Use &lt;script&gt; carefully</p>' ) );
		$this->assertSame( 'hi', jetonomy_content_to_plain( '<p>hi</p><script>alert(1)</script>' ) );
	}

	public function test_numeric_and_html5_entities_resolve(): void {
		$this->assertSame( "Bob's & Sue's", jetonomy_content_to_plain( '<p>Bob&#039;s &amp; Sue&apos;s</p>' ) );
	}

	public function test_empty_and_whitespace_only_content(): void {
		$this->assertSame( '', jetonomy_content_to_plain( '' ) );
		$this->assertSame( '', jetonomy_content_to_plain( '<p></p><p>  </p>' ) );
	}

	/** Blank lines collapse to at most one, so an excerpt is not mostly newlines. */
	public function test_runs_of_blank_lines_collapse(): void {
		$this->assertSame( "a\n\nb", jetonomy_content_to_plain( '<p>a</p><br /><br /><br /><p>b</p>' ) );
	}

	/** The write choke point derives the column, so every writer inherits the fix. */
	public function test_model_write_derives_the_fixed_plain_copy(): void {
		$space_id = \Jetonomy\Models\Space::create(
			array(
				'title'      => 'Plain copy',
				'slug'       => 'plain-copy-' . wp_generate_password( 6, false, false ),
				'visibility' => 'public',
			)
		);
		$post_id  = \Jetonomy\Models\Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => 1,
				'title'     => 'Plain copy',
				'content'   => '<p>R&amp;D notes.</p><blockquote>quoted</blockquote>',
			)
		);

		$this->assertIsInt( $space_id );
		$this->assertIsInt( $post_id );

		$post = \Jetonomy\Models\Post::find( $post_id );

		$this->assertSame( "R&D notes.\nquoted", $post->content_plain );
	}
}
