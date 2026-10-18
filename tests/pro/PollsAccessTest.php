<?php
/**
 * Polls: a poll on a topic the caller cannot read answers exactly like a
 * missing poll (404, same code and message), and a banned or silenced member
 * cannot vote or take a vote back.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\DB\Schema;

class PollsAccessTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;
	private int $author_id;
	private int $outsider_id;
	private int $private_post_id;
	private int $public_post_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		update_option( 'jetonomy_pro_extensions', array( 'polls' ) );
		$ext = new \Jetonomy_Pro\Extensions\Polls\Extension();
		$ext->activate();
		$ext->boot();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cat     = Category::create( array( 'name' => 'Poll Access', 'slug' => 'poll-access-' . uniqid() ) );
		$private = Space::create( array( 'title' => 'Private', 'slug' => 'pa-private-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		$public  = Space::create( array( 'title' => 'Public', 'slug' => 'pa-public-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		SpaceMember::add( $private, $this->author_id, 'member' );

		$this->private_post_id = Post::create( array( 'space_id' => $private, 'author_id' => $this->author_id, 'title' => 'Private poll topic', 'slug' => 'pp-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
		$this->public_post_id  = Post::create( array( 'space_id' => $public, 'author_id' => $this->author_id, 'title' => 'Public poll topic', 'slug' => 'pu-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function dispatch( string $method, string $route, array $params, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );
		return $this->server->dispatch( $request );
	}

	/** @return array{0:int,1:int} Poll id and first option id. */
	private function make_poll( int $post_id ): array {
		$res = $this->dispatch( 'POST', "/jetonomy/v1/posts/{$post_id}/poll", array( 'question' => 'Q?', 'options' => array( 'A', 'B' ) ), $this->author_id );
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data()['data'];
		return array( (int) $data['id'], (int) $data['options'][0]['id'] );
	}

	private function assert_same_as_missing( \WP_REST_Response $hidden, \WP_REST_Response $missing ): void {
		$this->assertSame( 404, $hidden->get_status() );
		$this->assertNotSame( 'rest_no_route', $hidden->get_data()['code'] ?? '' );
		$this->assertSame( $missing->get_status(), $hidden->get_status() );
		$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'] );
		$this->assertSame( $missing->get_data()['message'], $hidden->get_data()['message'] );
	}

	public function test_unreadable_poll_reads_like_missing(): void {
		$this->make_poll( $this->private_post_id );
		$hidden  = $this->dispatch( 'GET', "/jetonomy/v1/posts/{$this->private_post_id}/poll", array(), $this->outsider_id );
		$missing = $this->dispatch( 'GET', '/jetonomy/v1/posts/999999/poll', array(), $this->outsider_id );
		$this->assert_same_as_missing( $hidden, $missing );
	}

	public function test_unreadable_poll_vote_unvote_and_update_read_like_missing(): void {
		list( $poll_id, $option_id ) = $this->make_poll( $this->private_post_id );

		$this->assert_same_as_missing(
			$this->dispatch( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote", array( 'option_id' => $option_id ), $this->outsider_id ),
			$this->dispatch( 'POST', '/jetonomy/v1/polls/999999/vote', array( 'option_id' => $option_id ), $this->outsider_id )
		);
		$this->assert_same_as_missing(
			$this->dispatch( 'DELETE', "/jetonomy/v1/polls/{$poll_id}/vote", array(), $this->outsider_id ),
			$this->dispatch( 'DELETE', '/jetonomy/v1/polls/999999/vote', array(), $this->outsider_id )
		);
		$this->assert_same_as_missing(
			$this->dispatch( 'PATCH', "/jetonomy/v1/polls/{$poll_id}", array( 'closes_at' => '' ), $this->outsider_id ),
			$this->dispatch( 'PATCH', '/jetonomy/v1/polls/999999', array( 'closes_at' => '' ), $this->outsider_id )
		);
	}

	public function test_create_poll_on_unreadable_post_reads_like_missing(): void {
		$this->assert_same_as_missing(
			$this->dispatch( 'POST', "/jetonomy/v1/posts/{$this->private_post_id}/poll", array( 'question' => 'Q?', 'options' => array( 'A', 'B' ) ), $this->outsider_id ),
			$this->dispatch( 'POST', '/jetonomy/v1/posts/999999/poll', array( 'question' => 'Q?', 'options' => array( 'A', 'B' ) ), $this->outsider_id )
		);
	}

	/** @covers-existing A readable topic the caller cannot edit still answers 403. */
	public function test_create_poll_on_readable_post_of_another_member_is_403(): void {
		$res = $this->dispatch( 'POST', "/jetonomy/v1/posts/{$this->public_post_id}/poll", array( 'question' => 'Q?', 'options' => array( 'A', 'B' ) ), $this->outsider_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_forbidden', $res->get_data()['code'] );
	}

	public function test_silenced_member_cannot_vote_or_unvote(): void {
		list( $poll_id, $option_id ) = $this->make_poll( $this->public_post_id );
		$this->assertSame( 200, $this->dispatch( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote", array( 'option_id' => $option_id ), $this->outsider_id )->get_status() );

		$this->assertGreaterThan( 0, Restriction::ban( $this->outsider_id, 'silence', $this->admin_id ) );

		$vote = $this->dispatch( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote", array( 'option_id' => $option_id ), $this->outsider_id );
		$this->assertSame( 403, $vote->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $vote->get_data()['code'] );

		$unvote = $this->dispatch( 'DELETE', "/jetonomy/v1/polls/{$poll_id}/vote", array(), $this->outsider_id );
		$this->assertSame( 403, $unvote->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $unvote->get_data()['code'] );
	}

	/** @covers-existing The REST auth layer already refuses a banned member. */
	public function test_banned_member_is_told_they_are_banned_when_voting(): void {
		list( $poll_id, $option_id ) = $this->make_poll( $this->public_post_id );
		Restriction::ban( $this->outsider_id, 'global_ban', $this->admin_id );

		$vote = $this->dispatch( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote", array( 'option_id' => $option_id ), $this->outsider_id );
		$this->assertSame( 403, $vote->get_status() );
		$this->assertSame( 'jetonomy_user_banned', $vote->get_data()['code'] );
	}
}
