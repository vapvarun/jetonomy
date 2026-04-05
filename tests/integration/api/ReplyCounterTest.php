<?php
/**
 * Integration test: reply counter correctness.
 *
 * Verifies that creating and deleting replies via the model layer increments
 * and decrements denormalized counters exactly once.  This test would have
 * caught the double-counter bug where Reply::create() incremented reply_count
 * AND the Replies_Controller also incremented it.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

class ReplyCounterTest extends WP_UnitTestCase {

	private int $space_id;
	private int $post_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id         = Category::create( array( 'name' => 'Counter Test', 'slug' => 'counter-test' ) );
		$this->space_id = Space::create( array(
			'title'       => 'Counter Space',
			'slug'        => 'counter-space',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		) );

		$this->user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		// Ensure UserProfile row exists before tests.
		UserProfile::find_or_create( $this->user_id );

		$this->post_id = Post::create( array(
			'space_id'  => $this->space_id,
			'author_id' => $this->user_id,
			'title'     => 'Counter Post',
			'slug'      => 'counter-post',
			'content'   => '<p>Post for counter tests.</p>',
		) );
	}

	/**
	 * Helper: create a reply via the model (same path the controller uses).
	 */
	private function make_reply( array $overrides = array() ): int {
		return Reply::create( array_merge(
			array(
				'post_id'   => $this->post_id,
				'author_id' => $this->user_id,
				'content'   => '<p>A reply.</p>',
				'status'    => 'publish',
			),
			$overrides
		) );
	}

	/**
	 * After a single Reply::create(), the post's reply_count must be exactly 1.
	 *
	 * This is the primary regression test for the double-counter bug:
	 * if both the model AND the controller increment the counter,
	 * the count would be 2 instead of 1.
	 */
	public function test_reply_create_increments_counter_once(): void {
		$post_before  = Post::find( $this->post_id );
		$count_before = (int) $post_before->reply_count;

		$this->make_reply();

		$post_after = Post::find( $this->post_id );
		$this->assertSame(
			$count_before + 1,
			(int) $post_after->reply_count,
			'Post reply_count should increment by exactly 1 after a single Reply::create().'
		);
	}

	/**
	 * After a single Reply::create(), the user profile's reply_count must
	 * increment by exactly 1.
	 */
	public function test_reply_create_increments_user_profile_counter_once(): void {
		$profile_before = UserProfile::find_by_user( $this->user_id );
		$count_before   = (int) $profile_before->reply_count;

		$this->make_reply();

		$profile_after = UserProfile::find_by_user( $this->user_id );
		$this->assertSame(
			$count_before + 1,
			(int) $profile_after->reply_count,
			'User profile reply_count should increment by exactly 1 after a single Reply::create().'
		);
	}

	/**
	 * Creating multiple replies must result in counts that match exactly.
	 */
	public function test_multiple_replies_count_correctly(): void {
		$this->make_reply();
		$this->make_reply();
		$this->make_reply();

		$post    = Post::find( $this->post_id );
		$profile = UserProfile::find_by_user( $this->user_id );

		$this->assertSame( 3, (int) $post->reply_count, 'Post reply_count should be exactly 3 after 3 replies.' );
		$this->assertSame( 3, (int) $profile->reply_count, 'User profile reply_count should be exactly 3 after 3 replies.' );
	}

	/**
	 * The denormalized post reply_count must stay in sync with the actual
	 * number of reply rows in the database.
	 */
	public function test_denormalized_count_matches_actual_row_count(): void {
		$this->make_reply();
		$this->make_reply();
		$this->make_reply();

		$post       = Post::find( $this->post_id );
		$actual     = Reply::count_by_post( $this->post_id );

		$this->assertSame(
			$actual,
			(int) $post->reply_count,
			'Denormalized reply_count on Post must equal the actual COUNT(*) of reply rows.'
		);
	}

	/**
	 * Deleting a reply (soft-delete via controller pattern) must decrement
	 * both the post and user profile counters by exactly 1.
	 */
	public function test_reply_delete_decrements_counter(): void {
		$reply_id = $this->make_reply();

		$post_after_create    = Post::find( $this->post_id );
		$profile_after_create = UserProfile::find_by_user( $this->user_id );
		$this->assertSame( 1, (int) $post_after_create->reply_count );
		$this->assertSame( 1, (int) $profile_after_create->reply_count );

		// Simulate the controller's soft-delete path: decrement counters, then trash.
		$reply = Reply::find( $reply_id );
		Post::increment_reply_count( (int) $reply->post_id, -1 );
		UserProfile::increment_reply_count( (int) $reply->author_id, -1 );
		Reply::update( $reply_id, array( 'status' => 'trash' ) );

		$post_after_delete    = Post::find( $this->post_id );
		$profile_after_delete = UserProfile::find_by_user( $this->user_id );

		$this->assertSame(
			0,
			(int) $post_after_delete->reply_count,
			'Post reply_count should be 0 after deleting the only reply.'
		);
		$this->assertSame(
			0,
			(int) $profile_after_delete->reply_count,
			'User profile reply_count should be 0 after deleting the only reply.'
		);
	}

	/**
	 * Counters must never go below zero even after excessive decrements.
	 *
	 * The model uses GREATEST(reply_count + N, 0) to floor at zero.
	 */
	public function test_counter_never_goes_negative(): void {
		// Decrement on a post with 0 replies.
		Post::increment_reply_count( $this->post_id, -1 );

		$post = Post::find( $this->post_id );
		$this->assertGreaterThanOrEqual( 0, (int) $post->reply_count, 'reply_count must never go negative.' );
	}

	/**
	 * Replies by different users increment the post counter once each,
	 * but only the respective user profile counters.
	 */
	public function test_replies_by_different_users_count_correctly(): void {
		$user_a = $this->user_id;
		$user_b = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $user_b );

		$this->make_reply( array( 'author_id' => $user_a ) );
		$this->make_reply( array( 'author_id' => $user_a ) );
		$this->make_reply( array( 'author_id' => $user_b ) );

		$post      = Post::find( $this->post_id );
		$profile_a = UserProfile::find_by_user( $user_a );
		$profile_b = UserProfile::find_by_user( $user_b );

		$this->assertSame( 3, (int) $post->reply_count, 'Post should have 3 total replies.' );
		$this->assertSame( 2, (int) $profile_a->reply_count, 'User A should have 2 replies.' );
		$this->assertSame( 1, (int) $profile_b->reply_count, 'User B should have 1 reply.' );
	}
}
