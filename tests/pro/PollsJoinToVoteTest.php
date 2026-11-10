<?php
/**
 * Polls in a space that needs approval to join: anyone who can read the topic
 * sees the poll and its results, but voting and taking a vote back follow the
 * rule that decides replying there, so a non-member is told to join first.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;

class PollsJoinToVoteTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $author_id;
	private int $member_id;
	private int $outsider_id;
	private int $post_id;

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

		$this->author_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$space = Space::create( array( 'title' => 'Mentorship', 'slug' => 'pj-approval-' . uniqid(), 'visibility' => 'public', 'join_policy' => 'approval' ) );
		SpaceMember::add( $space, $this->author_id, 'member' );
		SpaceMember::add( $space, $this->member_id, 'member' );
		$this->post_id = Post::create( array( 'space_id' => $space, 'author_id' => $this->author_id, 'title' => 'Pick a time', 'slug' => 'pj-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
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

	public function test_non_member_reads_but_must_join_to_vote(): void {
		$made    = $this->dispatch( 'POST', "/jetonomy/v1/posts/{$this->post_id}/poll", array( 'question' => 'When?', 'options' => array( 'Mon', 'Tue' ) ), $this->author_id );
		$poll    = $made->get_data()['data'];
		$poll_id = (int) $poll['id'];
		$option  = (int) $poll['options'][0]['id'];

		$read = $this->dispatch( 'GET', "/jetonomy/v1/posts/{$this->post_id}/poll", array(), $this->outsider_id );
		$this->assertSame( 200, $read->get_status(), 'reading the poll stays open' );
		$data = $read->get_data()['data'];
		$this->assertFalse( $data['can_vote'] );
		// An approval space asks members to request to join.
		$this->assertStringContainsString( 'Request to join this', (string) $data['vote_refusal'] );

		$vote = $this->dispatch( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote", array( 'option_id' => $option ), $this->outsider_id );
		$this->assertSame( 403, $vote->get_status() );
		$this->assertSame( 'jetonomy_join_required', $vote->get_data()['code'] );

		$unvote = $this->dispatch( 'DELETE', "/jetonomy/v1/polls/{$poll_id}/vote", array(), $this->outsider_id );
		$this->assertSame( 403, $unvote->get_status() );
		$this->assertSame( 'jetonomy_join_required', $unvote->get_data()['code'] );

		$ok = $this->dispatch( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote", array( 'option_id' => $option ), $this->member_id );
		$this->assertSame( 200, $ok->get_status(), 'a member votes' );
		$this->assertTrue( $this->dispatch( 'GET', "/jetonomy/v1/posts/{$this->post_id}/poll", array(), $this->member_id )->get_data()['data']['can_vote'] );
	}
}
