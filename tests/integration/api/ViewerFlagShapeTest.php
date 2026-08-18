<?php
/**
 * Integration test: viewer_flagged on posts and replies (1.9.3).
 *
 * Reporting content was a write with no matching read. The duplicate-report
 * rule lived server-side (a second report is answered 409
 * jetonomy_already_flagged) but the API published nothing a client could read
 * it from, so the app tracked "reported" in local component state: it survived
 * until the next refresh, after which the control drew itself un-reported, the
 * member tapped again, and the server silently 409'd (Basecamp 10202766654).
 *
 * Same shape of gap as can_downvote - a server-owned rule the client was left
 * to guess - so it gets the same treatment and the same kind of test.
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
use Jetonomy\Models\Flag;

class ViewerFlagShapeTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $author_id;
	private int $reporter_id;
	private int $bystander_id;
	private int $post_id;
	private int $reply_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		Flag::reset_memo();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat            = Category::create(
			array(
				'name' => 'FlagCtx Cat',
				'slug' => 'flagctx-cat-' . uniqid(),
			)
		);
		$this->space_id = Space::create(
			array(
				'title'       => 'FlagCtx Space',
				'slug'        => 'flagctx-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);

		$this->author_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->reporter_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->bystander_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( $this->author_id, $this->reporter_id, $this->bystander_id ) as $uid ) {
			SpaceMember::add( $this->space_id, $uid, 'member' );
		}

		$this->post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Flag context post',
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
		Flag::reset_memo();
		parent::tear_down();
	}

	private function post_flag(): array {
		Flag::reset_memo();
		return (array) $this->server->dispatch(
			new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}" )
		)->get_data();
	}

	private function reply_flag(): array {
		Flag::reset_memo();
		$body = (array) $this->server->dispatch(
			new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}/replies" )
		)->get_data();

		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $this->reply_id ) {
				return (array) $row;
			}
		}
		return array();
	}

	private function report( string $type, int $id ): int {
		$req = new WP_REST_Request( 'POST', '/jetonomy/v1/flags' );
		$req->set_param( 'object_type', $type );
		$req->set_param( 'object_id', $id );
		$req->set_param( 'reason', 'spam' );
		return $this->server->dispatch( $req )->get_status();
	}

	public function test_post_reports_false_before_and_true_after(): void {
		wp_set_current_user( $this->reporter_id );

		$before = $this->post_flag();
		$this->assertArrayHasKey( 'viewer_flagged', $before );
		$this->assertFalse( $before['viewer_flagged'] );

		$this->assertSame( 201, $this->report( 'post', $this->post_id ) );

		$this->assertTrue( $this->post_flag()['viewer_flagged'] );
	}

	public function test_reply_reports_false_before_and_true_after(): void {
		wp_set_current_user( $this->reporter_id );

		$before = $this->reply_flag();
		$this->assertArrayHasKey( 'viewer_flagged', $before );
		$this->assertFalse( $before['viewer_flagged'] );

		$this->assertSame( 201, $this->report( 'reply', $this->reply_id ) );

		$this->assertTrue( $this->reply_flag()['viewer_flagged'] );
	}

	/**
	 * The state is the VIEWER's, not the object's. Someone else reporting a
	 * reply must not make it look reported to everyone - that would hide the
	 * report control from members who have not used it.
	 */
	public function test_flag_state_is_per_viewer(): void {
		wp_set_current_user( $this->reporter_id );
		$this->assertSame( 201, $this->report( 'reply', $this->reply_id ) );
		$this->assertTrue( $this->reply_flag()['viewer_flagged'] );

		wp_set_current_user( $this->bystander_id );
		$this->assertFalse( $this->reply_flag()['viewer_flagged'] );
	}

	public function test_logged_out_caller_reads_false(): void {
		wp_set_current_user( $this->reporter_id );
		$this->report( 'post', $this->post_id );

		wp_set_current_user( 0 );
		$this->assertFalse( $this->post_flag()['viewer_flagged'] );
	}

	/**
	 * viewer_flagged=true has to mean "reporting again would be refused", or
	 * the client is back to guessing - the same contract the flag exists to
	 * publish. A second report is a 409.
	 */
	public function test_flag_matches_the_endpoint_it_describes(): void {
		wp_set_current_user( $this->reporter_id );

		$this->assertFalse( $this->post_flag()['viewer_flagged'] );
		$this->assertSame( 201, $this->report( 'post', $this->post_id ) );

		$this->assertTrue( $this->post_flag()['viewer_flagged'] );
		$this->assertSame( 409, $this->report( 'post', $this->post_id ) );
	}

	/**
	 * The batch warm must agree with the per-row read, or a list and a detail
	 * view of the same reply disagree about whether you reported it.
	 */
	public function test_batch_map_agrees_with_per_row_read(): void {
		wp_set_current_user( $this->reporter_id );
		$this->report( 'reply', $this->reply_id );

		Flag::reset_memo();
		$map = Flag::reporter_flag_map( $this->reporter_id, 'reply', array( $this->reply_id ) );
		$this->assertArrayHasKey( $this->reply_id, $map );

		Flag::reset_memo();
		$this->assertTrue( Flag::has_reported( $this->reporter_id, 'reply', $this->reply_id ) );
	}
}
