<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

class ReplyTest extends WP_UnitTestCase {

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id   = Category::create( [ 'name' => 'General', 'slug' => 'general-reply-' . uniqid() ] );
		$space_id = Space::create( [
			'title'       => 'Test Space',
			'slug'        => 'test-space-reply-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );
		$this->post_id = Post::create( [
			'space_id' => $space_id,
			'title'    => 'Test Post',
			'slug'     => 'test-post-reply-' . uniqid(),
			'content'  => '<p>Post body.</p>',
		] );
	}

	private function make_reply( array $overrides = [] ): int {
		return Reply::create( array_merge(
			[
				'post_id' => $this->post_id,
				'content' => '<p>A reply.</p>',
				'status'  => 'publish',
			],
			$overrides
		) );
	}

	public function test_create_returns_id(): void {
		$id = $this->make_reply();
		$this->assertGreaterThan( 0, $id );
	}

	public function test_find_returns_reply(): void {
		$id    = $this->make_reply( [ 'content' => '<p>Hello.</p>' ] );
		$reply = Reply::find( $id );
		$this->assertIsObject( $reply );
		$this->assertEquals( '<p>Hello.</p>', $reply->content );
	}

	public function test_list_by_post_oldest_sort(): void {
		$id1 = $this->make_reply();
		$id2 = $this->make_reply();
		$id3 = $this->make_reply();

		$replies = Reply::list_by_post( $this->post_id, 'oldest' );
		$this->assertCount( 3, $replies );
		$ids = array_map( fn( $r ) => (int) $r->id, $replies );
		// Oldest first — id1 should appear before id3.
		$this->assertLessThan( array_search( $id3, $ids ), array_search( $id1, $ids ) );
	}

	public function test_list_by_post_newest_sort(): void {
		$id1 = $this->make_reply();
		$id2 = $this->make_reply();
		$id3 = $this->make_reply();

		$replies = Reply::list_by_post( $this->post_id, 'newest' );
		$ids     = array_map( fn( $r ) => (int) $r->id, $replies );
		// Newest first — id3 should appear before id1.
		$this->assertLessThan( array_search( $id1, $ids ), array_search( $id3, $ids ) );
	}

	public function test_list_by_post_best_sort(): void {
		$id1 = $this->make_reply();
		$id2 = $this->make_reply();
		Reply::update( $id1, [ 'vote_score' => 1 ] );
		Reply::update( $id2, [ 'vote_score' => 10 ] );

		$replies = Reply::list_by_post( $this->post_id, 'best' );
		$this->assertGreaterThanOrEqual( 2, count( $replies ) );
		$this->assertGreaterThanOrEqual( (int) $replies[1]->vote_score, (int) $replies[0]->vote_score );
	}

	public function test_list_by_post_excludes_non_publish(): void {
		$pub_id  = $this->make_reply( [ 'status' => 'publish' ] );
		$spam_id = Reply::create( [
			'post_id' => $this->post_id,
			'content' => 'Spam content',
			'status'  => 'spam',
		] );

		$replies = Reply::list_by_post( $this->post_id );
		$ids     = array_map( fn( $r ) => (int) $r->id, $replies );
		$this->assertContains( $pub_id, $ids );
		$this->assertNotContains( $spam_id, $ids );
	}

	public function test_mark_accepted(): void {
		$id = $this->make_reply();
		Reply::mark_accepted( $id );
		$reply = Reply::find( $id );
		$this->assertEquals( 1, (int) $reply->is_accepted );
	}

	public function test_count_by_post(): void {
		$this->make_reply();
		$this->make_reply();
		$this->make_reply();

		$count = Reply::count_by_post( $this->post_id );
		$this->assertEquals( 3, $count );
	}

	public function test_count_by_post_includes_all_statuses(): void {
		$this->make_reply( [ 'status' => 'publish' ] );
		Reply::create( [
			'post_id' => $this->post_id,
			'content' => 'Spammy',
			'status'  => 'spam',
		] );

		// count_by_post counts all rows regardless of status.
		$count = Reply::count_by_post( $this->post_id );
		$this->assertEquals( 2, $count );
	}

	public function test_create_increments_post_reply_count(): void {
		$post_before   = Post::find( $this->post_id );
		$count_before  = (int) $post_before->reply_count;

		$this->make_reply();
		$this->make_reply();

		$post_after = Post::find( $this->post_id );
		$this->assertEquals( $count_before + 2, (int) $post_after->reply_count );
	}

	public function test_delete(): void {
		$id = $this->make_reply();
		Reply::delete( $id );
		$this->assertNull( Reply::find( $id ) );
	}
}
