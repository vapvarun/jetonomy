<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;

class PostTest extends WP_UnitTestCase {

	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id         = Category::create( [ 'name' => 'General', 'slug' => 'general-post-' . uniqid() ] );
		$this->space_id = Space::create( [
			'title'       => 'Test Space',
			'slug'        => 'test-space-post-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );
	}

	private function make_post( array $overrides = [] ): int {
		return Post::create( array_merge(
			[
				'space_id' => $this->space_id,
				'title'    => 'Sample Post ' . uniqid(),
				'content'  => '<p>Content here.</p>',
				'status'   => 'publish',
			],
			$overrides
		) );
	}

	public function test_create_returns_id(): void {
		$id = $this->make_post();
		$this->assertGreaterThan( 0, $id );
	}

	public function test_create_generates_slug_from_title(): void {
		$id   = Post::create( [
			'space_id' => $this->space_id,
			'title'    => 'Hello World Post',
			'content'  => 'Body',
		] );
		$post = Post::find( $id );
		$this->assertNotEmpty( $post->slug );
		$this->assertEquals( 'hello-world-post', $post->slug );
	}

	public function test_find_by_slug(): void {
		Post::create( [
			'space_id' => $this->space_id,
			'title'    => 'Specific Post',
			'slug'     => 'specific-post',
			'content'  => 'Body',
		] );

		$post = Post::find_by_slug( 'specific-post' );
		$this->assertNotNull( $post );
		$this->assertEquals( 'Specific Post', $post->title );
	}

	public function test_find_by_slug_returns_null_for_missing(): void {
		$this->assertNull( Post::find_by_slug( 'slug-that-does-not-exist' ) );
	}

	public function test_list_by_space_latest_sort(): void {
		$id1 = $this->make_post( [ 'title' => 'First', 'slug' => 'first' ] );
		$id2 = $this->make_post( [ 'title' => 'Second', 'slug' => 'second' ] );
		$id3 = $this->make_post( [ 'title' => 'Third', 'slug' => 'third' ] );

		$posts = Post::list_by_space( $this->space_id, 'latest' );
		$this->assertCount( 3, $posts );
		// All should be in the same space.
		foreach ( $posts as $p ) {
			$this->assertEquals( $this->space_id, (int) $p->space_id );
		}
	}

	public function test_list_by_space_popular_sort(): void {
		$id1 = $this->make_post( [ 'slug' => 'low-votes' ] );
		$id2 = $this->make_post( [ 'slug' => 'high-votes' ] );
		Post::update( $id1, [ 'vote_score' => 2 ] );
		Post::update( $id2, [ 'vote_score' => 10 ] );

		$posts = Post::list_by_space( $this->space_id, 'popular' );
		$this->assertGreaterThanOrEqual( 2, count( $posts ) );
		$this->assertGreaterThanOrEqual( (int) $posts[1]->vote_score, (int) $posts[0]->vote_score );
	}

	public function test_list_by_space_unanswered_sort(): void {
		$id_answered   = $this->make_post( [ 'slug' => 'answered', 'reply_count' => 3 ] );
		$id_unanswered = $this->make_post( [ 'slug' => 'unanswered' ] );

		$posts = Post::list_by_space( $this->space_id, 'unanswered' );
		$ids   = array_map( fn( $p ) => (int) $p->id, $posts );
		$this->assertContains( $id_unanswered, $ids );
		$this->assertNotContains( $id_answered, $ids );
	}

	public function test_increment_reply_count(): void {
		$id = $this->make_post();
		Post::increment_reply_count( $id );
		Post::increment_reply_count( $id );
		$post = Post::find( $id );
		$this->assertEquals( 2, (int) $post->reply_count );
	}

	public function test_increment_view_count(): void {
		$id = $this->make_post();
		Post::increment_view_count( $id );
		Post::increment_view_count( $id );
		Post::increment_view_count( $id );
		$post = Post::find( $id );
		$this->assertEquals( 3, (int) $post->view_count );
	}

	public function test_close(): void {
		$id = $this->make_post();
		Post::close( $id );
		$post = Post::find( $id );
		$this->assertEquals( 1, (int) $post->is_closed );
	}

	public function test_pin(): void {
		$id = $this->make_post();
		Post::pin( $id );
		$post = Post::find( $id );
		$this->assertEquals( 1, (int) $post->is_sticky );
	}

	public function test_accept_reply(): void {
		$id = $this->make_post();
		Post::accept_reply( $id, 42 );
		$post = Post::find( $id );
		$this->assertEquals( 1, (int) $post->is_resolved );
		$this->assertEquals( 42, (int) $post->accepted_reply_id );
	}

	public function test_create_increments_space_post_count(): void {
		$space_before = Space::find( $this->space_id );
		$count_before = (int) $space_before->post_count;

		$this->make_post();
		$this->make_post();

		$space_after = Space::find( $this->space_id );
		$this->assertEquals( $count_before + 2, (int) $space_after->post_count );
	}

	public function test_list_by_space_excludes_non_publish(): void {
		$pub_id   = $this->make_post( [ 'slug' => 'pub-status', 'status' => 'publish' ] );
		$draft_id = Post::create( [
			'space_id' => $this->space_id,
			'title'    => 'Draft Post',
			'slug'     => 'draft-post',
			'content'  => 'Draft content',
			'status'   => 'draft',
		] );

		$posts = Post::list_by_space( $this->space_id, 'latest' );
		$ids   = array_map( fn( $p ) => (int) $p->id, $posts );
		$this->assertContains( $pub_id, $ids );
		$this->assertNotContains( $draft_id, $ids );
	}
}
