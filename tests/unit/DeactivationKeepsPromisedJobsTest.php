<?php
/**
 * Deactivation (step one of every manual update) cancels recomputable jobs and leaves pending the ones that finish
 * something a member or the owner already did: a space purge and a scheduled topic among them.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Cron;
use Jetonomy\Queue;
use Jetonomy\Space_Purge;
use Jetonomy\Recount_Backfill;

class DeactivationKeepsPromisedJobsTest extends WP_UnitTestCase {

	private const KEPT      = array( Space_Purge::BATCH_HOOK, Cron::PUBLISH_HOOK, 'jetonomy_fanout_post_subscribers' );
	private const CANCELLED = array( Cron::DUE_HOOK, 'jetonomy_trust_evaluation_batch', Recount_Backfill::BATCH_HOOK, 'jetonomy_gravatar_check' );

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( Queue::has_as(), 'Action Scheduler ships with Jetonomy: these proofs must run, never skip' );
	}

	public function tear_down(): void {
		foreach ( array_merge( self::KEPT, self::CANCELLED ) as $hook ) {
			Queue::cancel( $hook );
		}
		parent::tear_down();
	}

	private function pending( string $hook ): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => 'pending',
					'per_page' => 10,
				),
				'ids'
			)
		);
	}

	public function test_deactivation_keeps_a_space_purge_and_a_scheduled_topic_and_cancels_recomputable_jobs(): void {
		as_enqueue_async_action( Space_Purge::BATCH_HOOK, array( 'space_id' => 42 ), Queue::GROUP );
		as_schedule_single_action( time() + DAY_IN_SECONDS, Cron::PUBLISH_HOOK, array(), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_fanout_post_subscribers', array( 7, 0 ), Queue::GROUP );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, Cron::DUE_HOOK, array(), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_trust_evaluation_batch', array(), Queue::GROUP );
		as_enqueue_async_action( Recount_Backfill::BATCH_HOOK, array(), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_gravatar_check', array( 3 ), Queue::GROUP );

		\Jetonomy\Jetonomy::instance()->deactivate();

		foreach ( self::KEPT as $hook ) {
			$this->assertSame( 1, $this->pending( $hook ), $hook . ' finishes something already done: kept pending' );
		}
		foreach ( self::CANCELLED as $hook ) {
			$this->assertSame( 0, $this->pending( $hook ), $hook . ' is recomputed: cancelled' );
		}
	}

	/** Pro: an extension's deactivate() goes through the same list. */
	public function test_a_pro_extension_deactivation_keeps_the_announcement_deliveries_and_reindex(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		require_once JETONOMY_PRO_DIR . 'includes/extensions/site-announcements/class-extension.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/webhooks/class-extension.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/custom-fields/class-extension.php';
		as_enqueue_async_action( 'jetonomy_pro_announce_post', array( 5, 0 ), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_pro_deliver_webhook', array( 1, 'e', '{}' ), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_pro_reindex_field', array( 3, 0 ), Queue::GROUP );
		try {
			( new \Jetonomy_Pro\Extensions\Site_Announcements\Extension() )->deactivate();
			( new \Jetonomy_Pro\Extensions\Webhooks\Extension() )->deactivate();
			( new \Jetonomy_Pro\Extensions\Custom_Fields\Extension() )->deactivate();
			$this->assertSame( 1, $this->pending( 'jetonomy_pro_reindex_field' ), 'a re-index in progress is kept: its cursor is in the args' );
			$this->assertSame( 1, $this->pending( 'jetonomy_pro_announce_post' ) );
			$this->assertSame( 1, $this->pending( 'jetonomy_pro_deliver_webhook' ), 'a webhook delivery is kept: its body lives only in the job' );
		} finally {
			Queue::cancel( 'jetonomy_pro_announce_post' );
			Queue::cancel( 'jetonomy_pro_deliver_webhook' );
			Queue::cancel( 'jetonomy_pro_reindex_field' );
		}
	}

	/** Run one pending row the way another plugin's runner would. */
	private function run_row( int $id ): void {
		\ActionScheduler::runner()->process_action( $id, 'WP Cron' );
	}

	/** A row's status, '' once it is deleted (DBStore throws for a missing row). */
	private function status( int $id ): string {
		try {
			return (string) \ActionScheduler::store()->get_status( (string) $id );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return '';
		}
	}

	/**
	 * While Jetonomy is off another plugin's runner fails a kept row ("no callbacks are registered"); after activation
	 * the next scheduling request queues it again with the same args and deletes the failed row. A row that really ran
	 * and threw stays failed.
	 */
	public function test_a_kept_job_failed_while_off_is_queued_again_after_activation(): void {
		$args   = array( 'space_id' => 4242 );
		$orphan = (int) as_enqueue_async_action( Space_Purge::BATCH_HOOK, $args, Queue::GROUP );
		\Jetonomy\Jetonomy::instance()->deactivate();
		$this->assertSame( 'pending', $this->status( $orphan ) );

		remove_all_actions( Space_Purge::BATCH_HOOK ); // Jetonomy is off: no listener (the test case restores hooks).
		$this->run_row( $orphan );
		$this->assertSame( 'failed', $this->status( $orphan ), 'no listener: Action Scheduler fails the row' );

		$boom = static function () {
			throw new \RuntimeException( 'database went away' );
		};
		add_action( Space_Purge::BATCH_HOOK, $boom );
		$real = (int) as_enqueue_async_action( Space_Purge::BATCH_HOOK, array( 'space_id' => 4343 ), Queue::GROUP );
		$this->run_row( $real );
		remove_action( Space_Purge::BATCH_HOOK, $boom );
		$this->assertSame( 'failed', $this->status( $real ) );

		add_action( Space_Purge::BATCH_HOOK, '__return_null' ); // Jetonomy is on again: the hook has a listener.
		Queue::flag_recovery(); // As activate() does.
		add_filter( 'wp_doing_cron', '__return_true' ); // The next scheduling request.
		try {
			$this->assertSame( 1, Queue::recover_kept_jobs() );
			$this->assertSame( 0, Queue::recover_kept_jobs(), 'the flag is cleared once a pass finds less than a batch' );
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => Space_Purge::BATCH_HOOK,
				'status'   => 'pending',
				'per_page' => 10,
			)
		);
		$this->assertCount( 1, $pending, 'one pending copy' );
		$this->assertSame( $args, reset( $pending )->get_args(), 'same args' );
		$this->assertNotSame( 'failed', $this->status( $orphan ), 'the failed row is deleted' );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id = %d", $orphan ) ), 'with its logs' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'failed', $this->status( $real ), 'a job that really ran and errored stays failed' );
		\ActionScheduler::store()->delete_action( (string) $real );
	}

	/** A kept row that failed more than RECOVER_WINDOW_DAYS ago (an old fan-out) is never replayed. */
	public function test_a_kept_row_failed_long_ago_is_not_replayed(): void {
		global $wpdb;
		$old = (int) as_enqueue_async_action( 'jetonomy_fanout_post_subscribers', array( 77, 0 ), Queue::GROUP );
		remove_all_actions( 'jetonomy_fanout_post_subscribers' );
		$this->run_row( $old );
		$this->assertSame( 'failed', $this->status( $old ) );
		$at = gmdate( 'Y-m-d H:i:s', time() - ( Queue::RECOVER_WINDOW_DAYS + 1 ) * DAY_IN_SECONDS );
		$wpdb->update(
			$wpdb->prefix . 'actionscheduler_actions',
			array(
				'last_attempt_gmt'   => $at,
				'last_attempt_local' => $at,
			),
			array( 'action_id' => $old )
		); // phpcs:ignore WordPress.DB

		add_action( 'jetonomy_fanout_post_subscribers', '__return_null' );
		Queue::flag_recovery();
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			$this->assertSame( 0, Queue::recover_kept_jobs() );
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
		$this->assertSame( 'failed', $this->status( $old ), 'left failed, not replayed to members' );
		$this->assertSame( 0, $this->pending( 'jetonomy_fanout_post_subscribers' ) );
		\ActionScheduler::store()->delete_action( (string) $old );
	}

	/**
	 * Parallel recovery: 452 rows failed while Jetonomy was off. While the first request recovers them, a runner
	 * completes the first copy and a second request starts on the same rows (the copy's store hook stands in for the
	 * moment both are in flight). Each row is claimed before its copy is queued, so the second request skips what the
	 * first holds: 452 rows give 452 runs, none twice, and no failed row is left.
	 */
	public function test_parallel_recovery_with_a_runner_finishing_a_copy_midway_runs_each_row_once(): void {
		$hook = Space_Purge::BATCH_HOOK;
		remove_all_actions( $hook ); // Jetonomy is off: no listener.
		for ( $i = 1; $i <= 452; $i++ ) {
			$this->run_row( (int) as_enqueue_async_action( $hook, array( 'space_id' => 900000 + $i ), Queue::GROUP ) );
		}
		global $wpdb;
		$failed = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'failed'", $hook ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 452, $failed() );

		$runs = array();
		add_action(
			$hook,
			static function ( $space_id ) use ( &$runs ) {
				$runs[] = (int) $space_id;
			}
		);
		$raced = false;
		$race  = function ( $action_id ) use ( &$raced, $hook ) {
			if ( $raced || \ActionScheduler::store()->fetch_action( (string) $action_id )->get_hook() !== $hook ) {
				return;
			}
			$raced = true;
			$this->run_row( (int) $action_id ); // A runner completes the first copy...
			Queue::recover_kept_jobs();         // ...while a second request reaches the same rows.
		};
		add_action( 'action_scheduler_stored_action', $race );
		Queue::flag_recovery();
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			for ( $pass = 0; $pass < 10 && get_transient( Queue::RECOVER_FLAG ); $pass++ ) {
				Queue::recover_kept_jobs();
			}
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
			remove_action( 'action_scheduler_stored_action', $race );
		}
		$this->assertTrue( $raced );
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => 'pending',
				'per_page' => 1000,
			),
			'ids'
		) as $id ) {
			$this->run_row( (int) $id );
		}
		$this->assertSame( 0, $failed(), 'no failed row left' );
		$this->assertCount( 452, $runs, '452 rows, 452 runs' );
		$this->assertSame( 452, count( array_unique( $runs ) ), 'each row ran once' );
	}

	/** The set-based cancel touches only pending rows of the exact hooks in our group: never another group's rows. */
	public function test_the_set_based_cancel_touches_only_our_pending_rows_of_the_given_hooks(): void {
		$ours    = (int) as_enqueue_async_action( 'jetonomy_gravatar_check', array( 1 ), Queue::GROUP );
		$foreign = (int) as_enqueue_async_action( 'jetonomy_gravatar_check', array( 2 ), 'another-plugin' );
		$kept    = (int) as_enqueue_async_action( Space_Purge::BATCH_HOOK, array( 'space_id' => 5 ), Queue::GROUP );
		$other   = (int) as_enqueue_async_action( Cron::DUE_HOOK, array(), Queue::GROUP );
		$this->assertSame( 1, Queue::cancel_on_deactivate( array( 'jetonomy_gravatar_check', Space_Purge::BATCH_HOOK ) ) );
		$this->assertSame( 'canceled', $this->status( $ours ) );
		$this->assertSame( 'pending', $this->status( $foreign ), 'another group\'s row is never touched' );
		$this->assertSame( 'pending', $this->status( $kept ), 'KEEP_ON_DEACTIVATE stays pending' );
		$this->assertSame( 'pending', $this->status( $other ), 'a hook not passed stays pending' );
		\ActionScheduler::store()->delete_action( (string) $foreign );
		\ActionScheduler::store()->delete_action( (string) $other );
	}

	/** Pro's deactivation repeats its keep list (it must work with Free off): it must equal Queue's Pro entries. */
	public function test_pros_own_keep_list_matches_queue(): void {
		if ( ! defined( 'JETONOMY_PRO_KEEP_ON_DEACTIVATE' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		$pro = array_values( array_filter( Queue::KEEP_ON_DEACTIVATE, static fn( $h ) => str_starts_with( $h, 'jetonomy_pro_' ) ) );
		$this->assertEqualsCanonicalizing( $pro, JETONOMY_PRO_KEEP_ON_DEACTIVATE );
	}
}
