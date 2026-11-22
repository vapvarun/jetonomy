<?php
/**
 * \Jetonomy\Queue rules Free owns, proved without Pro: the history prune counts age from when a job finished,
 * keeps pending and running rows, stops at its time budget and survives a row another process deleted first;
 * async_once() and at( unique ) add one copy; the 2.0.7 is_queued( $hook, $args, false ) form still asks the
 * pending-only question.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Queue;

class QueueTest extends WP_UnitTestCase {

	private const HOOK = 'jetonomy_test_queue_job';

	public function set_up(): void {
		parent::set_up();
		if ( ! Queue::has_as() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
	}

	public function tear_down(): void {
		Queue::$clock = null;
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'actionscheduler_actions', array( 'hook' => self::HOOK ) ); // phpcs:ignore WordPress.DB
		wp_unschedule_hook( self::HOOK );
		parent::tear_down();
	}

	/** Our rows of the test hook, any status. */
	private function rows(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", self::HOOK ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A finished row: scheduled $scheduled_days ago, finished (modified) $finished_days ago.
	 *
	 * @param string $status         complete, canceled or failed.
	 * @param int    $scheduled_days Days since its scheduled time.
	 * @param int    $finished_days  Days since it finished.
	 */
	private function finished( string $status, int $scheduled_days, int $finished_days ): int {
		global $wpdb;
		$id    = (int) as_schedule_single_action( time() - $scheduled_days * DAY_IN_SECONDS, self::HOOK, array( uniqid( '', true ) ), Queue::GROUP );
		$store = \ActionScheduler::store();
		if ( 'complete' === $status ) {
			$store->mark_complete( $id );
		} elseif ( 'failed' === $status ) {
			$store->mark_failure( $id );
		} else {
			$store->cancel_action( $id );
		}
		$at = gmdate( 'Y-m-d H:i:s', time() - $finished_days * DAY_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'last_attempt_gmt' => $at, 'last_attempt_local' => $at ), array( 'action_id' => $id ) ); // phpcs:ignore WordPress.DB
		return $id;
	}

	public function test_prune_counts_age_from_when_a_job_finished_and_keeps_pending_and_running_rows(): void {
		$overdue_done_today = $this->finished( 'complete', 30, 0 );
		$old_done           = $this->finished( 'complete', 30, 8 );
		$old_cancelled      = $this->finished( 'canceled', 8, 0 ); // A cancelled row never ran: aged by its scheduled time.
		$recent_cancelled   = $this->finished( 'canceled', 1, 0 );
		$failed_60_days     = $this->finished( 'failed', 90, 60 );
		$failed_100_days    = $this->finished( 'failed', 120, 100 );
		$old_pending        = (int) as_schedule_single_action( time() - 30 * DAY_IN_SECONDS, self::HOOK, array( 'pending' ), Queue::GROUP );
		$old_running        = (int) as_schedule_single_action( time() - 30 * DAY_IN_SECONDS, self::HOOK, array( 'running' ), Queue::GROUP );
		\ActionScheduler::store()->log_execution( $old_running );

		Queue::prune_history();

		$rows = $this->rows();
		$this->assertContains( $overdue_done_today, $rows, 'a job that sat overdue and finished today is kept 7 days' );
		$this->assertNotContains( $old_done, $rows );
		$this->assertNotContains( $old_cancelled, $rows, 'cancelled rows follow the same 7 days, from their scheduled time' );
		$this->assertContains( $recent_cancelled, $rows );
		$this->assertContains( $failed_60_days, $rows, 'failed rows are kept 90 days' );
		$this->assertNotContains( $failed_100_days, $rows );
		$this->assertContains( $old_pending, $rows, 'pending rows are never pruned' );
		$this->assertContains( $old_running, $rows, 'running rows are never pruned' );
	}

	public function test_prune_stops_at_its_time_budget_and_the_next_run_continues(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->finished( 'complete', 30, 30 );
		}
		$now          = 1000.0;
		Queue::$clock = static function () use ( &$now ): float {
			return $now += 1.0; // Every read of the clock is one second later.
		};

		Queue::prune_history();
		$left = count( $this->rows() );
		$this->assertGreaterThan( 0, $left, 'the run stopped at its budget with rows left' );
		$this->assertLessThan( 25, $left, 'it deleted some first' );
		$this->assertGreaterThanOrEqual( 25 - Queue::TIME_BUDGET, $left, 'no more than one row per budgeted second' );

		Queue::$clock = null;
		Queue::prune_history();
		$this->assertSame( array(), $this->rows(), 'the next run clears the rest' );
	}

	public function test_a_row_deleted_by_another_process_during_the_prune_does_not_stop_it(): void {
		global $wpdb;
		$ids  = array( $this->finished( 'complete', 30, 30 ), $this->finished( 'complete', 30, 30 ), $this->finished( 'complete', 30, 30 ) );
		$once = false;
		$race = static function () use ( &$once, $ids, $wpdb ): void {
			if ( ! $once ) {
				$once = true; // Action Scheduler's cleaner removes the other two between our read and our deletes.
				$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB
			}
		};
		add_action( 'action_scheduler_deleted_action', $race );
		try {
			Queue::prune_history(); // Would throw InvalidArgumentException from delete_action() without the catch.
		} finally {
			remove_action( 'action_scheduler_deleted_action', $race );
		}
		$this->assertTrue( $once );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_async_once_adds_one_copy_and_a_second_only_while_one_runs(): void {
		Queue::async_once( self::HOOK, array( 5 ) );
		Queue::async_once( self::HOOK, array( 5 ) );
		$pending = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'status' => 'pending' ), 'ids' );
		$this->assertCount( 1, $pending, 'two calls, one copy' );

		\ActionScheduler::store()->log_execution( (int) reset( $pending ) ); // That copy starts running.
		Queue::async_once( self::HOOK, array( 5 ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => self::HOOK, 'status' => 'pending' ), 'ids' ), 'a running copy may have read old data: one more is queued' );
		$this->assertFalse( wp_next_scheduled( self::HOOK, array( 5 ) ), 'not on WP-Cron' );
	}

	public function test_at_with_unique_inserts_one_copy(): void {
		$this->assertTrue( Queue::at( time() + HOUR_IN_SECONDS, self::HOOK, array(), true ) );
		$this->assertFalse( Queue::at( time() + 2 * HOUR_IN_SECONDS, self::HOOK, array(), true ) );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_the_2_0_7_pending_only_form_of_is_queued_still_answers_pending_only(): void {
		$this->setExpectedDeprecated( 'Jetonomy\Queue::is_queued' );
		$id = (int) as_enqueue_async_action( self::HOOK, array(), Queue::GROUP );
		\ActionScheduler::store()->log_execution( $id );

		$this->assertTrue( Queue::is_queued( self::HOOK, array() ), 'a running copy counts by default' );
		$this->assertFalse( Queue::is_queued( self::HOOK, array(), false ), 'false as the third argument: pending only, as in 2.0.7' );
	}
}
