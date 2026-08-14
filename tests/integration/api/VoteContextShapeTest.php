<?php
/**
 * Integration test: the viewer-relative vote contract on posts AND replies (1.9.3).
 *
 * Two shape gaps sat behind the app's vote bugs, and both are the kind that
 * only a shape assertion catches:
 *
 *  - `can_downvote`. Votes_Controller answers a self-downvote with 400 and the
 *    web templates hide the control on your own content, but the API published
 *    neither the rule nor (for anonymous posts) anything a client could derive
 *    it from - so the app drew a button the server always refused.
 *  - `viewer_vote` on REPLIES. prepare_post has carried it since 1.6.0;
 *    prepare_reply never did, so clients seeded every reply at 0 and an upvote
 *    tap on an already-upvoted reply re-sent value=1, which the server reads as
 *    a toggle and REMOVES. That shipped unnoticed for three minor versions
 *    because nothing asserted the reply shape.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;

class VoteContextShapeTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $author_id;
	private int $other_id;
	private int $post_id;
	private int $reply_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat            = Category::create(
			array(
				'name' => 'VoteCtx Cat',
				'slug' => 'votectx-cat-' . uniqid(),
			)
		);
		$this->space_id = Space::create(
			array(
				'title'       => 'VoteCtx Space',
				'slug'        => 'votectx-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);

		$this->author_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->space_id, $this->author_id, 'member' );
		SpaceMember::add( $this->space_id, $this->other_id, 'member' );

		$this->post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Vote context post',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$this->reply_id = Reply::create(
			array(
				'post_id'   => $this->post_id,
				'author_id' => $this->author_id,
				'content'   => 'Reply body',
				'status'    => 'publish',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function get_post_data(): array {
		return (array) $this->server->dispatch(
			new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}" )
		)->get_data();
	}

	private function get_first_reply(): array {
		$body = (array) $this->server->dispatch(
			new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}/replies" )
		)->get_data();

		$items = $body['data'] ?? $body['items'] ?? $body;
		return (array) ( $items[0] ?? array() );
	}

	// ---- can_downvote -----------------------------------------------------

	public function test_author_may_not_downvote_own_post(): void {
		wp_set_current_user( $this->author_id );

		$data = $this->get_post_data();

		$this->assertArrayHasKey( 'can_downvote', $data );
		$this->assertFalse( $data['can_downvote'] );
	}

	public function test_other_member_may_downvote_the_post(): void {
		wp_set_current_user( $this->other_id );

		$this->assertTrue( $this->get_post_data()['can_downvote'] );
	}

	public function test_author_may_not_downvote_own_reply(): void {
		wp_set_current_user( $this->author_id );

		$reply = $this->get_first_reply();

		$this->assertArrayHasKey( 'can_downvote', $reply );
		$this->assertFalse( $reply['can_downvote'] );
	}

	public function test_other_member_may_downvote_the_reply(): void {
		wp_set_current_user( $this->other_id );

		$this->assertTrue( $this->get_first_reply()['can_downvote'] );
	}

	public function test_logged_out_caller_may_not_downvote(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->get_post_data()['can_downvote'] );
		$this->assertFalse( $this->get_first_reply()['can_downvote'] );
	}

	/**
	 * The flag must agree with what the vote endpoint actually does, or the
	 * client is back to guessing. can_downvote=false has to mean "this request
	 * would be refused", not merely "we would rather you didn't".
	 */
	public function test_flag_matches_the_endpoint_it_describes(): void {
		wp_set_current_user( $this->author_id );
		$this->assertFalse( $this->get_post_data()['can_downvote'] );

		$req = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/vote" );
		$req->set_param( 'value', -1 );
		$this->assertSame( 400, $this->server->dispatch( $req )->get_status() );

		wp_set_current_user( $this->other_id );
		$this->assertTrue( $this->get_post_data()['can_downvote'] );

		$req = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/vote" );
		$req->set_param( 'value', -1 );
		$this->assertSame( 200, $this->server->dispatch( $req )->get_status() );
	}

	// ---- reply viewer_vote ------------------------------------------------

	public function test_reply_carries_the_viewers_existing_vote(): void {
		Vote::cast( $this->other_id, 'reply', $this->reply_id, 1 );
		wp_set_current_user( $this->other_id );

		$reply = $this->get_first_reply();

		$this->assertArrayHasKey( 'viewer_vote', $reply );
		$this->assertSame( 1, $reply['viewer_vote'] );
	}

	public function test_reply_viewer_vote_is_per_viewer_not_global(): void {
		Vote::cast( $this->other_id, 'reply', $this->reply_id, 1 );

		// The author has not voted, so their copy of the same reply must read 0
		// - the bug this guards against is a client showing someone else's vote
		// state, or every client seeing 0 regardless.
		wp_set_current_user( $this->author_id );
		$this->assertSame( 0, $this->get_first_reply()['viewer_vote'] );

		wp_set_current_user( $this->other_id );
		$this->assertSame( 1, $this->get_first_reply()['viewer_vote'] );
	}

	public function test_reply_viewer_vote_is_zero_for_logged_out(): void {
		Vote::cast( $this->other_id, 'reply', $this->reply_id, -1 );
		wp_set_current_user( 0 );

		$this->assertSame( 0, $this->get_first_reply()['viewer_vote'] );
	}
}
