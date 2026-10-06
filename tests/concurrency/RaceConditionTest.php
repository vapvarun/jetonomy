<?php
/**
 * Race condition and counter consistency tests.
 *
 * These tests simulate sequential operations that in a real server would be
 * concurrent. The goal is to verify that the model layer enforces
 * idempotency and correct counter denormalization regardless of how many
 * times the same operation is called.
 *
 * @package Jetonomy\Tests\Concurrency
 */
namespace Jetonomy\Tests\Concurrency;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\DB\Schema;

class RaceConditionTest extends WP_UnitTestCase {

	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id         = Category::create( [ 'name' => 'Race Cat', 'slug' => 'race-cat-' . uniqid() ] );
		$this->space_id = Space::create( [
			'title'       => 'Race Space',
			'slug'        => 'race-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );
	}

	/**
	 * Calling Vote::cast() twice with the same value for the same user
	 * toggles the vote off on the second call — the net score must never
	 * exceed 1 (the original single upvote).
	 */
	public function test_double_vote_same_user_does_not_double_count(): void {
		$voter   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id = Post::create( [
			'space_id' => $this->space_id,
			'title'    => 'Double Vote Post',
			'slug'     => 'double-vote-' . uniqid(),
			'content'  => '<p>x</p>',
			'status'   => 'publish',
		] );

		// First cast: vote added → score becomes 1.
		Vote::cast( $voter, 'post', $post_id, 1 );

		// Second identical cast: toggle off → score returns to 0.
		Vote::cast( $voter, 'post', $post_id, 1 );

		$post = Post::find( $post_id );

		// Score must be 0 (toggled off) or at most 1 — never 2.
		$this->assertLessThanOrEqual( 1, (int) $post->vote_score,
			'Double-casting the same vote must not accumulate beyond 1.' );
	}

	/**
	 * Ten distinct users each cast one upvote. The post score must equal
	 * exactly 10 — no double-counting, no lost updates.
	 */
	public function test_multiple_users_vote_counter_consistent(): void {
		$author  = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id = Post::create( [
			'space_id'  => $this->space_id,
			'author_id' => $author,
			'title'     => 'Multi Vote Post',
			'slug'      => 'multi-vote-' . uniqid(),
			'content'   => '<p>x</p>',
			'status'    => 'publish',
		] );

		for ( $i = 0; $i < 10; $i++ ) {
			$voter = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
			Vote::cast( $voter, 'post', $post_id, 1 );
		}

		$post = Post::find( $post_id );

		$this->assertEquals( 10, (int) $post->vote_score,
			'Ten distinct upvotes must produce a score of exactly 10.' );
	}

	/**
	 * Five replies are created sequentially on a single post. The
	 * denormalized reply_count on the post must reflect exactly 5.
	 */
	public function test_reply_count_matches_actual_replies(): void {
		$author  = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id = Post::create( [
			'space_id'  => $this->space_id,
			'author_id' => $author,
			'title'     => 'Reply Count Post',
			'slug'      => 'reply-count-' . uniqid(),
			'content'   => '<p>x</p>',
			'status'    => 'publish',
		] );

		for ( $i = 1; $i <= 5; $i++ ) {
			Reply::create( [
				'post_id'       => $post_id,
				'author_id'     => $author,
				'content'       => "<p>Reply {$i}</p>",
				'content_plain' => "Reply {$i}",
				'status'        => 'publish',
			] );
		}

		$post = Post::find( $post_id );

		$this->assertEquals( 5, (int) $post->reply_count,
			'reply_count on the post must equal exactly 5 after 5 publised replies.' );
	}

	/**
	 * Accepting the same reply twice must be idempotent: no exception is
	 * thrown, and the post retains the correct accepted_reply_id.
	 */
	public function test_double_accept_answer_is_idempotent(): void {
		$author  = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id = Post::create( [
			'space_id'  => $this->space_id,
			'author_id' => $author,
			'title'     => 'Double Accept Post',
			'slug'      => 'double-accept-' . uniqid(),
			'content'   => '<p>x</p>',
			'status'    => 'publish',
		] );

		$reply_id = Reply::create( [
			'post_id'       => $post_id,
			'author_id'     => $author,
			'content'       => '<p>The answer.</p>',
			'content_plain' => 'The answer.',
			'status'        => 'publish',
		] );

		// First accept.
		Reply::mark_accepted( $reply_id );
		Post::accept_reply( $post_id, $reply_id );

		// Second accept — must not crash or corrupt state.
		Reply::mark_accepted( $reply_id );
		Post::accept_reply( $post_id, $reply_id );

		$post = Post::find( $post_id );

		$this->assertEquals( $reply_id, (int) $post->accepted_reply_id,
			'accepted_reply_id must remain correct after a double accept.' );
	}

	/**
	 * Two concurrent accepts of the same reply award once (Basecamp 10148436758).
	 *
	 * Replays the interleave that awarded +30: request A has flipped the
	 * reply's is_accepted but not yet written the post, when request B runs.
	 * B used to see "not fully accepted", redo the writes and award again.
	 * The flip is now one conditional UPDATE, so B loses the row and only
	 * heals the post side.
	 */
	public function test_concurrent_accept_awards_once(): void {
		$asker    = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$answerer = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$qa_space = Space::create(
			[
				'title' => 'Race QA',
				'slug'  => 'race-qa-' . uniqid(),
				'type'  => 'qa',
			],
			0
		);
		$post_id  = Post::create( [
			'space_id'  => $qa_space,
			'author_id' => $asker,
			'title'     => 'Race accept',
			'slug'      => 'race-accept-' . uniqid(),
			'content'   => '<p>q</p>',
			'status'    => 'publish',
		] );
		$reply_id = Reply::create( [
			'post_id'   => $post_id,
			'author_id' => $answerer,
			'content'   => '<p>a</p>',
			'status'    => 'publish',
		] );

		$awards = 0;
		$count  = static function () use ( &$awards ) {
			++$awards;
		};
		add_action( 'jetonomy_reply_accepted', $count );

		// Request A mid-flight: reply row flipped, post row not yet written.
		$this->assertTrue( Reply::mark_accepted( $reply_id ) );
		$this->assertFalse( Reply::mark_accepted( $reply_id ), 'A second flip must lose the row.' );

		// Request B arrives now.
		$this->assertTrue( Reply::accept_as_answer( $reply_id, $asker ) );
		$this->assertSame( 0, $awards, 'The losing request fired the accept hook / award.' );
		$this->assertSame( $reply_id, (int) Post::find( $post_id )->accepted_reply_id, 'Loser must still heal the post side.' );

		// Un-accept: two in a row revoke once.
		$this->assertTrue( Reply::unaccept_as_answer( $reply_id, $asker ) );
		$again = Reply::unaccept_as_answer( $reply_id, $asker );
		$this->assertWPError( $again );
		$this->assertSame( 'jetonomy_not_accepted', $again->get_error_code() );

		// A clean accept wins the row and awards exactly once.
		$this->assertTrue( Reply::accept_as_answer( $reply_id, $asker ) );
		$this->assertTrue( Reply::accept_as_answer( $reply_id, $asker ) );
		$this->assertSame( 1, $awards );
	}
}
