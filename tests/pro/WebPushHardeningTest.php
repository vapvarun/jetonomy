<?php
/**
 * Web Push: a browser endpoint belongs to whoever subscribed last, the server
 * only accepts public https endpoints, a private message's text never reaches
 * a lock screen, and the send re-checks the endpoint host.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Notification;
use Jetonomy\DB\Schema;

class WebPushHardeningTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private \Jetonomy_Pro\Extensions\Web_Push\Extension $ext;
	private int $alice;
	private int $bob;

	/** @var array[] Args of the push cursor jobs queued during the test. */
	private array $queued = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		$this->ext = new \Jetonomy_Pro\Extensions\Web_Push\Extension();
		$this->ext->activate();
		$this->ext->boot();
		update_option( 'jetonomy_pro_web_push', array( 'enabled' => true ) );

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->alice = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Alice' ) );
		$this->bob   = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Bob' ) );

		$capture = function ( $pre, $event ) {
			if ( 'jetonomy_pro_send_push_batch' === $event->hook ) {
				$this->queued[] = $event->args;
				return true;
			}
			return $pre;
		};
		add_filter( 'pre_schedule_event', $capture, 10, 2 );
		add_filter(
			'pre_as_enqueue_async_action',
			function ( $pre, $hook, $args ) {
				if ( 'jetonomy_pro_send_push_batch' === $hook ) {
					$this->queued[] = $args;
					return 1;
				}
				return $pre;
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function store( int $user_id, string $endpoint, string $p256dh ): void {
		$m = new \ReflectionMethod( $this->ext, 'store_subscription' );
		$m->invoke( $this->ext, $user_id, $endpoint, $p256dh, 'auth-' . $user_id );
	}

	private function subscription_row( string $endpoint ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_pro_push_subscriptions WHERE endpoint = %s", $endpoint ) );
	}

	public function test_resubscribing_a_shared_browser_moves_the_endpoint_to_the_new_member(): void {
		$endpoint = 'https://fcm.googleapis.com/fcm/send/shared-' . uniqid();
		$this->store( $this->alice, $endpoint, 'key-alice' );
		$this->store( $this->bob, $endpoint, 'key-bob' );

		$row = $this->subscription_row( $endpoint );
		$this->assertSame( $this->bob, (int) $row->user_id );
		$this->assertSame( 'key-bob', $row->p256dh );
		$this->assertSame( 'auth-' . $this->bob, $row->auth );
	}

	/** @dataProvider unsafe_endpoints */
	public function test_subscribe_rejects_endpoints_the_server_must_not_post_to( string $endpoint ): void {
		wp_set_current_user( $this->alice );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/push/subscribe' );
		$request->set_body_params( array( 'endpoint' => $endpoint, 'p256dh' => 'k', 'auth' => 'a' ) );
		$res = $this->server->dispatch( $request );

		$this->assertSame( 400, $res->get_status() );
		$this->assertNull( $this->subscription_row( $endpoint ) );
	}

	public function unsafe_endpoints(): array {
		return array(
			'plain http'   => array( 'http://fcm.googleapis.com/fcm/send/abc' ),
			'loopback'     => array( 'https://127.0.0.1/push' ),
			'private lan'  => array( 'https://192.168.1.10/push' ),
		);
	}

	/**
	 * Send one notification through the push cursor job to Bob's app device and return the body Expo received.
	 */
	private function pushed_body( string $type, string $object_type, int $object_id, string $message ): ?string {
		$device = new \ReflectionMethod( $this->ext, 'store_device' );
		$device->setAccessible( true );
		$device->invoke( $this->ext, $this->bob, 'ExponentPushToken[bob' . uniqid() . ']', 'ios', 'Phone' );
		$id = Notification::create( array( 'user_id' => $this->bob, 'actor_id' => $this->alice, 'type' => $type, 'object_type' => $object_type, 'object_id' => $object_id, 'message' => $message ) );
		$this->ext->on_notification_created( $id, $this->bob, $type, $object_type, $object_id, $message, '' );
		$this->ext->flush_pending();
		$this->assertCount( 1, $this->queued, 'one push cursor job per push' );

		$bodies = array();
		$expo   = static function ( $pre, $r, $url ) use ( &$bodies ) {
			if ( false === strpos( (string) $url, 'exp.host' ) ) {
				return $pre;
			}
			$sent     = (array) json_decode( (string) $r['body'], true );
			$bodies[] = $sent['body'] ?? null;
			return array( 'body' => '{"data":[]}', 'response' => array( 'code' => 200 ), 'headers' => array(), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $expo, 5, 3 );
		$this->ext->do_send_batch( ...array_values( $this->queued[0] ) );
		remove_filter( 'pre_http_request', $expo, 5 );
		$this->assertCount( 1, $bodies );
		return $bodies[0];
	}

	public function test_private_message_push_names_the_sender_but_not_the_text(): void {
		$this->assertSame( 'New message from Alice', $this->pushed_body( 'message', 'message', 7, 'Alice: my bank PIN is 1234' ) );
	}

	/** @covers-existing A forum notification keeps its sentence. */
	public function test_forum_notification_push_keeps_its_message(): void {
		$this->assertSame( 'Alice replied to your topic', $this->pushed_body( 'reply_to_post', 'reply', 3, 'Alice replied to your topic' ) );
	}

	/**
	 * A slow push service: the clock is read before every send, a run stops after its budget (the first member is
	 * always sent in full) and queues the rest of its own rows from the last member fully sent, so every member gets
	 * the push exactly once across runs. The clock is injected (Queue::$clock): each send costs 0.4 s of fake time,
	 * so the test asserts the deadline logic, not wall-clock speed.
	 */
	public function test_a_slow_push_service_splits_the_send_across_runs_without_repeats(): void {
		$device = new \ReflectionMethod( $this->ext, 'store_device' );
		$device->setAccessible( true );
		$members = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$uid             = self::factory()->user->create();
			$members[ $uid ] = 'ExponentPushToken[slow' . $i . uniqid() . ']';
			$device->invoke( $this->ext, $uid, $members[ $uid ], 'ios', 'Phone' );
			$id = Notification::create( array( 'user_id' => $uid, 'actor_id' => $this->alice, 'type' => 'new_post', 'object_type' => 'post', 'object_id' => 9, 'message' => 'Alice posted' ) );
			$this->ext->on_notification_created( $id, $uid, 'new_post', 'post', 9, 'Alice posted', '' );
		}
		$this->ext->flush_pending();
		$this->assertCount( 1, $this->queued );

		$now               = 1000.0;
		\Jetonomy\Queue::$clock = static function () use ( &$now ): float {
			return $now;
		};
		$sent = array();
		$slow = static function ( $pre, $r, $url ) use ( &$sent, &$now ) {
			if ( false === strpos( (string) $url, 'exp.host' ) ) {
				return $pre;
			}
			$now   += 0.4; // Each send takes 0.4 s of fake time.
			$sent[] = ( (array) json_decode( (string) $r['body'], true ) )['to'] ?? '';
			return array( 'body' => '{"data":[]}', 'response' => array( 'code' => 200 ), 'headers' => array(), 'cookies' => array() );
		};
		$budget = static fn() => 1;
		add_filter( 'pre_http_request', $slow, 5, 3 );
		add_filter( 'jetonomy_pro_push_send_seconds', $budget );
		try {
			$this->ext->do_send_batch( ...array_values( $this->queued[0] ) );
			// 0.4, 0.8 and 1.2 s: the clock passes the 1 s budget after the third send, so the fourth never starts.
			$this->assertCount( 3, $sent, 'the first run stopped at its time budget' );
			$this->assertCount( 2, $this->queued, 'the run queued its continuation' );
			$this->assertSame( array_values( $this->queued[0] )[2] >= array_values( $this->queued[1] )[2], true, 'the continuation stays within the first run\'s rows' );

			for ( $run = 1; $run < count( $this->queued ) && $run < 10; $run++ ) {
				$now = 1000.0;
				$this->ext->do_send_batch( ...array_values( $this->queued[ $run ] ) );
			}
		} finally {
			\Jetonomy\Queue::$clock = null;
			remove_filter( 'pre_http_request', $slow, 5 );
			remove_filter( 'jetonomy_pro_push_send_seconds', $budget );
		}

		sort( $sent );
		$expected = array_values( $members );
		sort( $expected );
		$this->assertSame( $expected, $sent, 'every member got the push exactly once' );
	}

	/**
	 * A member with several slow devices: the deadline is checked between that member's devices too, and the next
	 * run starts again from the last member fully sent, so at most that member's earlier devices are sent twice and
	 * no device is skipped. The first member of a run is always sent in full, so the job never loops.
	 */
	public function test_the_deadline_is_checked_between_one_members_devices(): void {
		$device = new \ReflectionMethod( $this->ext, 'store_device' );
		$device->setAccessible( true );
		$tokens = array();
		foreach ( array( 1, 3 ) as $count ) {
			$uid = self::factory()->user->create();
			for ( $d = 0; $d < $count; $d++ ) {
				$tokens[ $uid ][] = 'ExponentPushToken[dev' . $count . $d . uniqid() . ']';
				$device->invoke( $this->ext, $uid, end( $tokens[ $uid ] ), 'ios', 'Phone ' . $d );
			}
			$id = Notification::create( array( 'user_id' => $uid, 'actor_id' => $this->alice, 'type' => 'new_post', 'object_type' => 'post', 'object_id' => 11, 'message' => 'Alice posted again' ) );
			$this->ext->on_notification_created( $id, $uid, 'new_post', 'post', 11, 'Alice posted again', '' );
		}
		$this->ext->flush_pending();

		$now               = 1000.0;
		\Jetonomy\Queue::$clock = static function () use ( &$now ): float {
			return $now;
		};
		$sent = array();
		$slow = static function ( $pre, $r, $url ) use ( &$sent, &$now ) {
			if ( false === strpos( (string) $url, 'exp.host' ) ) {
				return $pre;
			}
			$now   += 0.4;
			$sent[] = ( (array) json_decode( (string) $r['body'], true ) )['to'] ?? '';
			return array( 'body' => '{"data":[]}', 'response' => array( 'code' => 200 ), 'headers' => array(), 'cookies' => array() );
		};
		$budget = static fn() => 1;
		add_filter( 'pre_http_request', $slow, 5, 3 );
		add_filter( 'jetonomy_pro_push_send_seconds', $budget );
		try {
			$this->ext->do_send_batch( ...array_values( $this->queued[0] ) );
			$this->assertCount( 3, $sent, 'member 1, then two of member 2\'s devices, then the clock stops the run' );
			$this->assertCount( 2, $this->queued );
			$now = 1000.0;
			$this->ext->do_send_batch( ...array_values( $this->queued[1] ) );
		} finally {
			\Jetonomy\Queue::$clock = null;
			remove_filter( 'pre_http_request', $slow, 5 );
			remove_filter( 'jetonomy_pro_push_send_seconds', $budget );
		}
		$this->assertCount( 2, $this->queued, 'the second run finished: no further continuation' );
		$counts = array_count_values( $sent );
		$second = array_values( $tokens )[1];
		$this->assertSame( 1, $counts[ array_values( $tokens )[0][0] ], 'member 1 once' );
		$this->assertSame( 1, $counts[ $second[2] ], 'the device the deadline stopped before is sent once' );
		$this->assertSame( array( 2, 2 ), array( $counts[ $second[0] ], $counts[ $second[1] ] ), 'only the stopped member\'s earlier devices are sent again' );
	}

	/** Push args with a real P-256 key, so the send gets as far as the POST. */
	private function push_args( string $endpoint ): array {
		$key = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		$ec  = openssl_pkey_get_details( $key )['ec'];
		$pub = "\x04" . str_pad( $ec['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $ec['y'], 32, "\0", STR_PAD_LEFT );
		$b64 = static fn( string $raw ): string => rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array( 'endpoint' => $endpoint, 'p256dh' => $b64( $pub ), 'auth' => $b64( random_bytes( 16 ) ), 'body' => 'b' );
	}

	/**
	 * A stored endpoint whose host now resolves to a private or metadata
	 * address (DNS changed after subscribe) is never called.
	 *
	 * @dataProvider private_send_endpoints
	 */
	public function test_push_send_skips_an_endpoint_that_now_points_at_a_private_address( string $endpoint ): void {
		$called = false;
		add_filter(
			'pre_http_request',
			static function () use ( &$called ) {
				$called = true;
				return array( 'response' => array( 'code' => 201 ), 'body' => '', 'headers' => array(), 'cookies' => array() );
			}
		);

		$this->ext->do_send_push( $this->push_args( $endpoint ) );

		$this->assertFalse( $called, 'No request may leave for ' . $endpoint );
	}

	public function private_send_endpoints(): array {
		return array(
			'cloud metadata' => array( 'https://169.254.169.254/latest/meta-data' ),
			'private lan'    => array( 'https://10.0.0.5/push' ),
		);
	}

	public function test_push_send_uses_the_safe_http_transport(): void {
		$seen = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen = $args;
				return array( 'response' => array( 'code' => 201 ), 'body' => '', 'headers' => array(), 'cookies' => array() );
			},
			10,
			3
		);

		// A public address literal: the send-time guard passes it without a DNS lookup.
		$this->ext->do_send_push( $this->push_args( 'https://8.8.8.8/fcm/send/x' ) );

		$this->assertNotNull( $seen, 'The push was sent.' );
		$this->assertTrue( $seen['reject_unsafe_urls'] ?? false, 'Member-supplied endpoints go through wp_safe_remote_post().' );
	}
}
