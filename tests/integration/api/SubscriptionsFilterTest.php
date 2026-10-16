<?php
/**
 * GET /subscriptions?object_type=&object_id= returns only that object's row.
 *
 * REGRESSION (2.0.2 journey C.member.subscribe-thread-and-space): the filter was
 * ignored, the topic Follow button deleted the first row it got back, and that
 * was the member's newest subscription - their space follow - while the topic
 * subscription stayed and kept notifying them.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use Jetonomy\Models\Subscription;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class SubscriptionsFilterTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function items( array $params ): array {
		$req = new WP_REST_Request( 'GET', '/jetonomy/v1/subscriptions' );
		$req->set_query_params( $params );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		return $data['data'] ?? $data;
	}

	public function test_object_filter_returns_only_that_subscription(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$post_sub = Subscription::subscribe( $user, 'post', 9001 );
		Subscription::subscribe( $user, 'space', 9002 ); // Newer row, listed first unfiltered.

		$items = $this->items( array( 'object_type' => 'post', 'object_id' => 9001 ) );
		$this->assertCount( 1, $items );
		$this->assertSame( $post_sub, (int) $items[0]['id'] );

		$this->assertSame( array(), $this->items( array( 'object_type' => 'post', 'object_id' => 9003 ) ) );
		$this->assertSame( array(), $this->items( array( 'object_type' => 'bogus', 'object_id' => 9001 ) ) );
		$this->assertCount( 2, $this->items( array() ) );
	}
}
