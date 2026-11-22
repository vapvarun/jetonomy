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
		if ( ! Queue::has_as() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
	}

	public function tear_down(): void {
		foreach ( array_merge( self::KEPT, self::CANCELLED ) as $hook ) {
			Queue::cancel( $hook );
		}
		parent::tear_down();
	}

	private function pending( string $hook ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'per_page' => 10 ), 'ids' ) );
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
	public function test_a_pro_extension_deactivation_keeps_the_announcement_and_cancels_its_sends(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		require_once JETONOMY_PRO_DIR . 'includes/extensions/site-announcements/class-extension.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/webhooks/class-extension.php';
		as_enqueue_async_action( 'jetonomy_pro_announce_post', array( 5, 0 ), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_pro_deliver_webhook', array( 1, 'e', '{}' ), Queue::GROUP );
		try {
			( new \Jetonomy_Pro\Extensions\Site_Announcements\Extension() )->deactivate();
			( new \Jetonomy_Pro\Extensions\Webhooks\Extension() )->deactivate();
			$this->assertSame( 1, $this->pending( 'jetonomy_pro_announce_post' ) );
			$this->assertSame( 0, $this->pending( 'jetonomy_pro_deliver_webhook' ) );
		} finally {
			Queue::cancel( 'jetonomy_pro_announce_post' );
			Queue::cancel( 'jetonomy_pro_deliver_webhook' );
		}
	}

	/** Run one pending row the way another plugin's runner would. */
	private function run_row( int $id ): void {
		\ActionScheduler::runner()->process_action( $id, 'WP Cron' );
	}

	private function status( int $id ): string {
		return (string) \ActionScheduler::store()->get_status( (string) $id );
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

		$pending = as_get_scheduled_actions( array( 'hook' => Space_Purge::BATCH_HOOK, 'status' => 'pending', 'per_page' => 10 ) );
		$this->assertCount( 1, $pending, 'one pending copy' );
		$this->assertSame( $args, reset( $pending )->get_args(), 'same args' );
		$this->assertNotSame( 'failed', $this->status( $orphan ), 'the failed row is deleted' );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id = %d", $orphan ) ), 'with its logs' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'failed', $this->status( $real ), 'a job that really ran and errored stays failed' );
		\ActionScheduler::store()->delete_action( (string) $real );
	}
}
