<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Avatar;
use Jetonomy\Recount_Backfill;

/**
 * Jobs queued from an ordinary page request leave one pending row when several requests arrive together.
 * Each loop pass stands in for a separate request that got past the request's own checks.
 */
class FirstRequestJobsQueueOnceTest extends WP_UnitTestCase {

	private function pending( string $hook, array $args ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ), 'ids' ) );
	}

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
	}

	public function test_gravatar_check_from_three_requests_queues_one(): void {
		$user = self::factory()->user->create();
		$seen = new \ReflectionProperty( Avatar::class, 'check_enqueued' );
		for ( $i = 0; $i < 3; $i++ ) {
			$seen->setValue( null, array() ); // A new request.
			delete_user_meta( $user, 'jetonomy_has_gravatar' ); // It read the flag before the others wrote it.
			Avatar::maybe_check_gravatar( $user );
		}
		$this->assertSame( 1, $this->pending( Avatar::CHECK_HOOK, array( $user ) ) );
	}

	public function test_recount_continuation_from_three_requests_queues_one(): void {
		as_unschedule_all_actions( Recount_Backfill::BATCH_HOOK );
		$enqueue = new \ReflectionMethod( Recount_Backfill::class, 'enqueue' );
		for ( $i = 0; $i < 3; $i++ ) {
			$enqueue->invoke( null );
		}
		$this->assertSame( 1, $this->pending( Recount_Backfill::BATCH_HOOK, array() ) );
	}
}
