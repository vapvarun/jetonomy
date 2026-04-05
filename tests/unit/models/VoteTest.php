<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\DB\Schema;

class VoteTest extends WP_UnitTestCase {

	private int $space_id;
	private int $post_id;
	private int $reply_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id         = Category::create( [ 'name' => 'General', 'slug' => 'general-vote-' . uniqid() ] );
		$this->space_id = Space::create( [
			'title'       => 'Vote Space',
			'slug'        => 'vote-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );
		$this->post_id  = Post::create( [
			'space_id' => $this->space_id,
			'title'    => 'Vote Test Post',
			'slug'     => 'vote-test-post-' . uniqid(),
			'content'  => '<p>Content.</p>',
		] );
		$this->reply_id = Reply::create( [
			'post_id' => $this->post_id,
			'content' => '<p>A reply.</p>',
		] );
		$this->user_id  = $this->factory()->user->create();
	}

	public function test_cast_new_vote_returns_created_action(): void {
		$result = Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		$this->assertEquals( 'created', $result['action'] );
		$this->assertNull( $result['old_value'] );
	}

	public function test_cast_same_vote_toggles_off(): void {
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		$result = Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		$this->assertEquals( 'removed', $result['action'] );
		$this->assertEquals( 1, $result['old_value'] );
	}

	public function test_cast_opposite_vote_changes_value(): void {
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		$result = Vote::cast( $this->user_id, 'post', $this->post_id, -1 );
		$this->assertEquals( 'updated', $result['action'] );
		$this->assertEquals( 1, $result['old_value'] );
	}

	public function test_get_user_vote_returns_null_before_voting(): void {
		$vote = Vote::get_user_vote( $this->user_id, 'post', $this->post_id );
		$this->assertNull( $vote );
	}

	public function test_get_user_vote_returns_value_after_voting(): void {
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		$vote = Vote::get_user_vote( $this->user_id, 'post', $this->post_id );
		$this->assertEquals( 1, $vote );
	}

	public function test_get_user_vote_returns_null_after_toggle_off(): void {
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 ); // toggle off
		$vote = Vote::get_user_vote( $this->user_id, 'post', $this->post_id );
		$this->assertNull( $vote );
	}

	public function test_upvote_increments_post_score(): void {
		$post_before = Post::find( $this->post_id );
		$score_before = (int) $post_before->vote_score;

		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );

		$post_after = Post::find( $this->post_id );
		$this->assertEquals( $score_before + 1, (int) $post_after->vote_score );
	}

	public function test_downvote_decrements_post_score(): void {
		$post_before  = Post::find( $this->post_id );
		$score_before = (int) $post_before->vote_score;

		Vote::cast( $this->user_id, 'post', $this->post_id, -1 );

		$post_after = Post::find( $this->post_id );
		$this->assertEquals( $score_before - 1, (int) $post_after->vote_score );
	}

	public function test_toggle_off_restores_post_score(): void {
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		$after_first_vote = (int) Post::find( $this->post_id )->vote_score;

		Vote::cast( $this->user_id, 'post', $this->post_id, 1 ); // toggle off

		$after_toggle = (int) Post::find( $this->post_id )->vote_score;
		$this->assertEquals( $after_first_vote - 1, $after_toggle );
	}

	public function test_opposite_vote_applies_net_delta_to_post(): void {
		Vote::cast( $this->user_id, 'post', $this->post_id, 1 ); // score +1
		Vote::cast( $this->user_id, 'post', $this->post_id, -1 ); // net delta = -2

		$post = Post::find( $this->post_id );
		$this->assertEquals( -1, (int) $post->vote_score );
	}

	public function test_vote_on_reply_updates_reply_score(): void {
		$reply_before = Reply::find( $this->reply_id );
		$score_before = (int) $reply_before->vote_score;

		Vote::cast( $this->user_id, 'reply', $this->reply_id, 1 );

		$reply_after = Reply::find( $this->reply_id );
		$this->assertEquals( $score_before + 1, (int) $reply_after->vote_score );
	}

	public function test_multiple_users_can_vote_independently(): void {
		$user2 = $this->factory()->user->create();

		Vote::cast( $this->user_id, 'post', $this->post_id, 1 );
		Vote::cast( $user2, 'post', $this->post_id, 1 );

		$post = Post::find( $this->post_id );
		$this->assertEquals( 2, (int) $post->vote_score );
	}
}
