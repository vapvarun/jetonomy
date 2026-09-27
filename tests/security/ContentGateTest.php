<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Permissions\Content_Gate;

/**
 * The reply gates, driven at the shared choke point rather than through one
 * controller.
 *
 * These checks existed only inside REST_Replies_Controller::create_item(), so
 * the inbound-email writer - which reaches Reply::create() through
 * `jetonomy_reply_from_email` and Notifier::on_reply_from_email() - had none of
 * them. Reproduced with correctly signed webhook requests, so none of it was
 * masked by the signature fix (Basecamp 10228771444): a BANNED member's emailed
 * reply was created, and replies landed on CLOSED posts and in ARCHIVED spaces.
 *
 * Testing through the gate is the point. Asserting via the REST controller
 * would prove only what that controller does, and "only that controller does
 * it" was the bug.
 */
class ContentGateTest extends WP_UnitTestCase {

	private int $category_id;
	private int $space_id;
	private int $post_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->category_id = (int) Category::create(
			[
				'name' => 'Gate',
				'slug' => 'gate-' . uniqid(),
			]
		);
		$this->space_id = (int) Space::create(
			[
				'title'       => 'Gate space',
				'slug'        => 'gate-space-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$this->post_id = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => 1,
				'title'     => 'Gate topic',
				'content'   => '<p>body</p>',
				'status'    => 'publish',
			]
		);
		$this->member_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function post(): object {
		return Post::find( $this->post_id );
	}

	public function test_an_ordinary_member_may_reply(): void {
		$this->assertTrue(
			Content_Gate::check( $this->member_id, $this->post() ),
			'the control case: a guard that refuses everything passes every negative test'
		);
	}

	public function test_a_banned_member_may_not_reply(): void {
		Restriction::ban( $this->member_id, 'global_ban', 1 );

		$result = Content_Gate::check( $this->member_id, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_user_banned', $result->get_error_code() );
	}

	public function test_a_silenced_member_may_not_reply(): void {
		Restriction::ban( $this->member_id, 'silence', 1 );

		$result = Content_Gate::check( $this->member_id, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_user_silenced', $result->get_error_code() );
	}

	public function test_a_closed_post_accepts_no_replies(): void {
		Post::close( $this->post_id );
		\Jetonomy\Cache::reset_memos();

		$result = Content_Gate::check( $this->member_id, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_post_closed', $result->get_error_code() );
	}

	public function test_an_archived_space_accepts_no_replies(): void {
		// Through the model, not a raw UPDATE: Space::update() busts the cached
		// row. A direct query leaves an "active" Space object cached under this
		// id, and because PHPUnit rolls the DB back while InnoDB reuses
		// auto-increment ids, the next test to be handed this id inherits it.
		Space::update( $this->space_id, [ 'status' => 'archived' ] );

		$result = Content_Gate::check( $this->member_id, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_space_restricted', $result->get_error_code() );
	}

	// ------------------------------------------------- space accepts content --

	/**
	 * space_accepts_content() is separate from check() because POST creation
	 * needs it and has no post object. It exists because the WP Abilities
	 * create-post and create-reply paths both had their own copy of the state
	 * rules, and both copies were short the space-status check - so either
	 * could write into an ARCHIVED space that REST refuses. Reproduced during
	 * the follow-up audit: REST returned 403 jetonomy_space_restricted while
	 * the ability created reply #2411 in that same space for that same member.
	 */
	public function test_an_archived_space_accepts_no_new_content(): void {
		Space::update( $this->space_id, [ 'status' => 'archived' ] );
		\Jetonomy\Cache::reset_memos();

		$result = Content_Gate::space_accepts_content( $this->space_id );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_space_restricted', $result->get_error_code() );
	}

	public function test_a_locked_space_accepts_no_new_content(): void {
		Space::update( $this->space_id, [ 'status' => 'locked' ] );
		\Jetonomy\Cache::reset_memos();

		$result = Content_Gate::space_accepts_content( $this->space_id );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_space_restricted', $result->get_error_code() );
	}

	public function test_an_active_space_accepts_content(): void {
		$this->assertTrue(
			Content_Gate::space_accepts_content( $this->space_id ),
			'the control case - a gate that refuses every space passes both tests above'
		);
	}

	/**
	 * These tests deliberately put users and spaces into states the rest of the
	 * suite does not expect - banned, silenced, archived, closed. The DB half
	 * of that is undone by the per-test transaction rollback, but the object
	 * cache is not transactional, so anything cached under an id survives into
	 * whichever later test is handed the same id. Flush so it cannot.
	 */
	public function tear_down(): void {
		// reset_memos() and nothing more. An earlier version called
		// wp_cache_flush() here, which fixed this test's own leak by wiping the
		// whole object cache for every test that followed - trading one
		// cross-test side effect for a broader one. It is not needed: the ban
		// and silence state lives in a Restriction memo registered with
		// Cache::register_memo_reset(), and the archived-space state is written
		// through Space::update(), which busts its own cached row.
		\Jetonomy\Cache::reset_memos();
		parent::tear_down();
	}

	public function test_a_logged_out_caller_may_not_reply(): void {
		$result = Content_Gate::check( 0, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_not_logged_in', $result->get_error_code() );
	}

	/*
	 * Trashed and unpublished targets (Basecamp 10335997408): a trashed topic
	 * took replies (201) and votes (200) from its author on every surface.
	 */

	public function test_a_trashed_post_accepts_no_replies(): void {
		Post::update( $this->post_id, [ 'status' => 'trash' ] );

		$result = Content_Gate::check( $this->member_id, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_already_trashed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_a_draft_post_accepts_no_replies(): void {
		Post::update( $this->post_id, [ 'status' => 'draft' ] );

		$result = Content_Gate::check( $this->member_id, $this->post() );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_not_published', $result->get_error_code() );
	}

	public function test_a_trashed_parent_reply_accepts_no_threaded_replies(): void {
		$reply_id = (int) Reply::create( [ 'post_id' => $this->post_id, 'author_id' => 1, 'content' => '<p>r</p>' ] );
		Reply::update( $reply_id, [ 'status' => 'trash' ] );

		$result = Content_Gate::check( $this->member_id, $this->post(), $reply_id );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_already_trashed', $result->get_error_code() );
	}

	public function test_live_targets_accept_writes_and_trashed_ones_do_not(): void {
		$reply_id = (int) Reply::create( [ 'post_id' => $this->post_id, 'author_id' => 1, 'content' => '<p>r</p>' ] );

		$this->assertTrue( Content_Gate::target_is_live( 'post', $this->post_id ), 'control: a published post is live' );
		$this->assertTrue( Content_Gate::target_is_live( 'reply', $reply_id ), 'control: a published reply is live' );

		Post::update( $this->post_id, [ 'status' => 'trash' ] );

		$this->assertSame( 'jetonomy_already_trashed', Content_Gate::target_is_live( 'post', $this->post_id )->get_error_code() );
		$this->assertSame(
			'jetonomy_already_trashed',
			Content_Gate::target_is_live( 'reply', $reply_id )->get_error_code(),
			'a live reply inside a trashed topic is not a live target'
		);
	}
}
