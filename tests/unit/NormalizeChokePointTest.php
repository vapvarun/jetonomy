<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

/**
 * The model-level normalize+kses choke point (Basecamp 10138808747, wave 4).
 *
 * QA's deep writer inspection found paths that kses'd WITHOUT normalizing
 * (wp-admin Content_Handler updates, Abilities creation, the three importers,
 * Pro reply-by-email journey) so contenteditable div soup could still persist.
 * All of those writers converge on Post/Reply::create/update, which now run
 * every 'content' write through jetonomy_sanitize_editor_content(). These are
 * PERSISTENCE tests: raw soup in, what the DB row holds out.
 */
class NormalizeChokePointTest extends WP_UnitTestCase {

	private const SOUP       = '<div>first line<br>second line</div><div><br></div><div>next paragraph</div>';
	private const NORMALIZED = "<p>first line<br />second line</p>\n<p>next paragraph</p>";

	private int $space_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->author_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cat_id          = Category::create( array( 'name' => 'Choke Cat', 'slug' => 'choke-cat-' . uniqid() ) );
		$this->space_id  = Space::create( array(
			'title'       => 'Choke Space',
			'slug'        => 'choke-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		) );
	}

	private function make_post( array $overrides = array() ): int {
		$id = Post::create( array_merge( array(
			'space_id'  => $this->space_id,
			'author_id' => $this->author_id,
			'title'     => 'Choke point post',
			'content'   => self::SOUP,
		), $overrides ) );
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_post_create_normalizes_raw_soup(): void {
		// The importer / Abilities shape: raw content straight into the model.
		$row = Post::find( $this->make_post() );
		$this->assertSame( self::NORMALIZED, $row->content, 'Post::create must normalize div soup' );
	}

	public function test_post_update_normalizes_raw_soup(): void {
		// The wp-admin Content_Handler shape: kses'd-but-unnormalized update.
		$id = $this->make_post( array( 'content' => '<p>clean</p>' ) );
		Post::update( $id, array( 'content' => wp_kses_post( self::SOUP ) ) );
		$this->assertSame( self::NORMALIZED, Post::find( $id )->content, 'Post::update must normalize div soup' );
	}

	public function test_reply_create_and_update_normalize_raw_soup(): void {
		$post_id  = $this->make_post( array( 'content' => '<p>parent</p>' ) );
		$reply_id = Reply::create( array(
			'post_id'   => $post_id,
			'author_id' => $this->author_id,
			'content'   => self::SOUP,
		) );
		$this->assertIsInt( $reply_id );
		$this->assertSame( self::NORMALIZED, Reply::find( $reply_id )->content, 'Reply::create must normalize' );

		Reply::update( $reply_id, array( 'content' => self::SOUP ) );
		$this->assertSame( self::NORMALIZED, Reply::find( $reply_id )->content, 'Reply::update must normalize' );
	}

	public function test_content_plain_derived_when_writer_omits_it(): void {
		// Writers that forgot content_plain used to create rows FULLTEXT
		// search could never find. The choke point derives it.
		$row = Post::find( $this->make_post() );
		$this->assertNotSame( '', (string) $row->content_plain, 'content_plain must be derived' );
		$this->assertStringContainsString( 'next paragraph', (string) $row->content_plain );
		$this->assertStringNotContainsString( '<', (string) $row->content_plain );
	}

	public function test_explicit_content_plain_is_respected(): void {
		// REST controllers derive their own plain copy - the choke point must
		// not clobber an explicit value.
		$id  = $this->make_post( array( 'content_plain' => 'explicit plain copy' ) );
		$row = Post::find( $id );
		$this->assertSame( 'explicit plain copy', (string) $row->content_plain );
	}

	public function test_clean_html_passes_byte_identical(): void {
		// The attributed-div gate: embeds and already-clean HTML must survive
		// the choke point untouched, or importers would corrupt rich source
		// content (the reason normalization is safe to enforce globally).
		$embed = '<figure class="embed"><div class="wrap"><iframe src="https://example.com/v"></iframe></div></figure><p>after</p>';
		$kept  = wp_kses_post( $embed );
		$row   = Post::find( $this->make_post( array( 'content' => $embed ) ) );
		$this->assertSame( $kept, $row->content, 'attributed-div content must pass byte-identical (post-kses)' );
	}
}
