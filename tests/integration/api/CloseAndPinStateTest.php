<?php
/**
 * Close and pin set the state the moderator asked for, so a second moderator
 * or a retried request cannot undo the first.
 *
 * Both routes used to toggle. A moderator closes a topic; another moderator's
 * tab still shows "Close", they click it, and the topic reopens. The buttons
 * now send the state they showed; a client that sends nothing still toggles.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\API;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class CloseAndPinStateTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $topic;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$cat         = Category::create( array( 'name' => 'Help', 'slug' => 'help-' . uniqid() ) );
		$space       = Space::create( array( 'title' => 'Help Desk', 'slug' => 'help-desk-' . uniqid(), 'category_id' => $cat ) );
		$this->topic = (int) Post::create( array( 'space_id' => $space, 'author_id' => self::factory()->user->create(), 'title' => 'Scheduled maintenance on Saturday', 'content' => 'Read-only for an hour.', 'status' => 'publish' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function send( string $action, array $body = array() ): \WP_REST_Response {
		$req = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->topic}/{$action}" );
		foreach ( $body as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->server->dispatch( $req );
	}

	public function test_a_second_close_from_a_stale_tab_keeps_the_topic_closed(): void {
		$this->send( 'close', array( 'closed' => true ) );
		$this->send( 'close', array( 'closed' => true ) ); // Second moderator, same button.
		$this->assertSame( 1, (int) Post::find( $this->topic )->is_closed, 'Closing twice must not reopen the topic' );

		$this->send( 'close', array( 'closed' => false ) );
		$this->send( 'close', array( 'closed' => false ) );
		$this->assertSame( 0, (int) Post::find( $this->topic )->is_closed, 'Reopening twice must not close it again' );
	}

	public function test_a_second_pin_from_a_stale_tab_keeps_the_topic_pinned(): void {
		$this->send( 'pin', array( 'pinned' => true ) );
		$this->send( 'pin', array( 'pinned' => true ) );
		$this->assertSame( 1, (int) Post::find( $this->topic )->is_sticky, 'Pinning twice must not unpin the topic' );
	}

	/**
	 * @covers-existing API clients that send no state keep the toggle.
	 */
	public function test_a_request_without_a_state_still_toggles(): void {
		$this->send( 'close' );
		$this->assertSame( 1, (int) Post::find( $this->topic )->is_closed );
		$this->send( 'close' );
		$this->assertSame( 0, (int) Post::find( $this->topic )->is_closed );
	}
}
