<?php
/**
 * One push written by several requests: Action Scheduler runs fan-out chunks of the same reply in two long-lived
 * runner requests (A runs chunks 1, 3, 5, 7; B runs 2, 4, 6, 8). Each request used to queue one push cursor job at
 * shutdown from the lowest to the highest notification id it saw, so A's range covered B's rows and both jobs sent
 * them (QA: 250 of 2,000 tokens twice). The push now flushes after every Action Scheduler action, so each job covers
 * one chunk. Driven through the real queue runner with a slow provider (40 ms per send, 2 s budget per run); 150 of
 * the 2,000 members have 6 devices, so time boxes stop mid-member and the continuation must resume at the next device.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\Models\Notification;
use Jetonomy\DB\Schema;

class WebPushNoDuplicatesTest extends WP_UnitTestCase {

	private const CHUNK_HOOK = 'jetonomy_test_push_chunk';

	public function test_a_push_written_by_two_runner_requests_reaches_each_of_2750_tokens_once(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) || ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro or Action Scheduler is not active.' );
		}
		Schema::create_tables();
		update_option( 'jetonomy_pro_web_push', array( 'enabled' => true ) );
		as_unschedule_all_actions( 'jetonomy_pro_send_push_batch' );
		$a = new \Jetonomy_Pro\Extensions\Web_Push\Extension();
		$b = new \Jetonomy_Pro\Extensions\Web_Push\Extension();
		$a->activate();
		$a->boot();
		$b->boot();
		remove_action( 'jetonomy_pro_send_push_batch', array( $b, 'do_send_batch' ), 10 ); // One sender, as on a live site.

		$device = new \ReflectionMethod( $a, 'store_device' );
		$device->setAccessible( true );
		$actor  = self::factory()->user->create();
		$tokens = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$tokens[] = 'ExponentPushToken[dup' . $i . ']';
			$device->invoke( $a, 500000 + $i, end( $tokens ), 'ios', 'Phone' ); // No user row is read on this path.
			if ( $i < 300 && 1 === $i % 2 ) { // 150 members with 5 more devices each: a time box can stop mid-member.
				for ( $d = 0; $d < 5; $d++ ) {
					$tokens[] = 'ExponentPushToken[dup' . $i . 'x' . $d . ']';
					$device->invoke( $a, 500000 + $i, end( $tokens ), 'ios', 'Tablet ' . $d );
				}
			}
		}
		$this->assertCount( 2750, $tokens );
		// Each chunk is one Action Scheduler action, written by "request" A or B (the extension instance holds the
		// request's collected pushes, as it does in a live runner request).
		$requests = array( 'a' => $a, 'b' => $b );
		$chunk    = static function ( $n ) use ( $requests, $actor ) {
			$ext = $requests[ 0 === $n % 2 ? 'a' : 'b' ];
			for ( $i = $n * 250; $i < ( $n + 1 ) * 250; $i++ ) {
				$id = Notification::create( array( 'user_id' => 500000 + $i, 'actor_id' => $actor, 'type' => 'reply', 'object_type' => 'reply', 'object_id' => 77, 'message' => 'Big push' ) );
				$ext->on_notification_created( $id, 500000 + $i, 'reply', 'reply', 77, 'Big push', '' );
			}
		};
		add_action( self::CHUNK_HOOK, $chunk );
		for ( $n = 0; $n < 8; $n++ ) {
			as_enqueue_async_action( self::CHUNK_HOOK, array( $n ), 'jetonomy' );
		}

		$now = 1000.0;
		\Jetonomy\Queue::set_clock( static function () use ( &$now ): float {
			return $now;
		} );
		$sent = array();
		$slow = static function ( $pre, $r, $url ) use ( &$sent, &$now ) {
			if ( false === strpos( (string) $url, 'exp.host' ) ) {
				return $pre;
			}
			$now   += 0.04; // 40 ms per send, in fake time.
			$sent[] = ( (array) json_decode( (string) $r['body'], true ) )['to'] ?? '';
			return array( 'body' => '{"data":[]}', 'response' => array( 'code' => 200 ), 'headers' => array(), 'cookies' => array() );
		};
		$budget = static fn() => 2;
		add_filter( 'pre_http_request', $slow, 5, 3 );
		add_filter( 'jetonomy_pro_push_send_seconds', $budget );
		$runs = 0;
		try {
			$runner = \ActionScheduler_QueueRunner::instance();
			while ( $runs < 500 && ( as_has_scheduled_action( self::CHUNK_HOOK ) || as_has_scheduled_action( 'jetonomy_pro_send_push_batch' ) ) ) {
				$runner->run( 'PHPUnit' );
				// Both requests end (shutdown) after every runner pass.
				$a->flush_pending();
				$b->flush_pending();
				++$runs;
			}
		} finally {
			\Jetonomy\Queue::set_clock( null );
			remove_action( self::CHUNK_HOOK, $chunk );
			remove_filter( 'pre_http_request', $slow, 5 );
			remove_filter( 'jetonomy_pro_push_send_seconds', $budget );
		}

		$this->assertFalse( as_has_scheduled_action( 'jetonomy_pro_send_push_batch' ), 'the push finished' );
		$twice = array_filter( array_count_values( $sent ), static fn( $n ) => $n > 1 );
		$this->assertSame( 0, count( $twice ), count( $twice ) . ' tokens were pushed twice' );
		sort( $sent );
		sort( $tokens );
		$this->assertSame( $tokens, $sent, 'every token got the push: 0 missing' );
	}
}
