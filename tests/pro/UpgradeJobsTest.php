<?php
/**
 * The 2.0.8 upgrade leaves no secret in the job tables, our cancelled rows are pruned, and work queued during the
 * upgrade request becomes an Action Scheduler job rather than a WP-Cron fallback.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\Queue;

class UpgradeJobsTest extends WP_UnitTestCase {

	private const LEGACY  = 'jetonomy_pro_dispatch_webhook';
	private const DELIVER = 'jetonomy_pro_deliver_webhook';
	private const LOCK    = 'jetonomy_pro_attachments_lock';

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) || ! Queue::has_as() ) {
			$this->markTestSkipped( 'Jetonomy Pro or Action Scheduler is not loaded.' );
		}
		require_once JETONOMY_PRO_DIR . 'includes/extensions/webhooks/class-extension.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/attachments/class-extension.php';
	}

	public function tear_down(): void {
		foreach ( array( self::LEGACY, self::DELIVER, self::LOCK ) as $hook ) {
			Queue::delete_rows( $hook );
			wp_unschedule_hook( $hook );
		}
		parent::tear_down();
	}

	/** Rows of a hook in any status. */
	private function rows( string $hook ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", $hook ) ); // phpcs:ignore WordPress.DB
	}

	public function test_upgrade_deletes_every_legacy_webhook_row_and_the_new_job_carries_no_secret(): void {
		global $wpdb;
		$keyed = array(
			'webhook_id' => 4242,
			'url'        => 'https://example.test/secret-endpoint',
			'secret'     => 'qa-secret-value',
			'payload'    => '{"event":"qa"}',
			'event'      => 'qa.event',
		);
		as_schedule_single_action( time() + HOUR_IN_SECONDS, self::LEGACY, $keyed, 'jetonomy' );
		$store  = \ActionScheduler::store();
		$failed = (int) as_schedule_single_action( time() - DAY_IN_SECONDS, self::LEGACY, $keyed + array( 'n' => 2 ), 'jetonomy' );
		$store->mark_failure( $failed );
		$done = (int) as_schedule_single_action( time() - DAY_IN_SECONDS, self::LEGACY, $keyed + array( 'n' => 3 ), 'jetonomy' );
		$store->mark_complete( $done );
		$ids = $this->rows( self::LEGACY );
		$this->assertCount( 3, $ids, 'fixture: three 2.0.7 rows' );

		( new \Jetonomy_Pro\Extensions\Webhooks\Extension() )->upgrade_jobs( '2.0.7' );

		$this->assertSame( array(), $this->rows( self::LEGACY ), 'no row of the old hook remains, in any status' );
		$logs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $logs, 'their log rows are gone too' );

		$new = as_get_scheduled_actions( array( 'hook' => self::DELIVER, 'status' => 'pending' ), 'objects' );
		$this->assertCount( 1, $new );
		$args = array_values( reset( $new )->get_args() );
		$this->assertSame( array( 4242, 'qa.event', '{"event":"qa"}' ), $args, 'webhook id, event and payload only' );
		$this->assertStringNotContainsString( 'qa-secret-value', (string) wp_json_encode( $args ) );
		$this->assertStringNotContainsString( 'secret-endpoint', (string) wp_json_encode( $args ) );
	}

	public function test_history_prune_deletes_our_old_cancelled_rows(): void {
		$old = (int) as_schedule_single_action( time() - 8 * DAY_IN_SECONDS, self::LEGACY, array( 1 ), 'jetonomy' );
		\ActionScheduler::store()->cancel_action( $old );
		$recent = (int) as_schedule_single_action( time() - DAY_IN_SECONDS, self::LEGACY, array( 2 ), 'jetonomy' );
		\ActionScheduler::store()->cancel_action( $recent );

		Queue::prune_history();

		$rows = array_map( 'intval', $this->rows( self::LEGACY ) );
		$this->assertNotContains( $old, $rows, 'a cancelled row older than the retention is deleted' );
		$this->assertContains( $recent, $rows, 'a recent one is kept' );
	}

	/**
	 * An upgrade runs activate() before Action Scheduler's store is ready. The lock sweep waits for the store and
	 * becomes an Action Scheduler job; nothing lands in WP-Cron.
	 */
	public function test_lock_sweep_queued_during_an_upgrade_request_is_an_action_scheduler_job(): void {
		global $wp_actions;
		$ext = new \Jetonomy_Pro\Extensions\Attachments\Extension();
		if ( ! $ext->is_enabled() ) {
			update_option( 'jetonomy_pro_extensions', array_merge( \Jetonomy_Pro\Extension::enabled_ids(), array( 'attachments' ) ), false );
		}
		$sweep = array( array( 'sweep' => 1 ) );

		$ready = $wp_actions['action_scheduler_init'];
		unset( $wp_actions['action_scheduler_init'] ); // The upgrade request, before the store loads.
		$before = $GLOBALS['wp_filter']['action_scheduler_init']->callbacks ?? array();
		try {
			$this->assertFalse( Queue::has_as() );
			$ext->activate();
			$this->assertFalse( wp_next_scheduled( self::LOCK, array_values( $sweep ) ), 'no WP-Cron fallback' );
		} finally {
			$wp_actions['action_scheduler_init'] = $ready;
		}
		// The store is ready: run only what activate() deferred to action_scheduler_init.
		foreach ( $GLOBALS['wp_filter']['action_scheduler_init']->callbacks as $priority => $callbacks ) {
			foreach ( array_diff_key( $callbacks, $before[ $priority ] ?? array() ) as $callback ) {
				call_user_func( $callback['function'] );
			}
		}
		$this->assertTrue( (bool) as_get_scheduled_actions( array( 'hook' => self::LOCK, 'args' => $sweep, 'status' => 'pending' ), 'ids' ), 'the sweep is an Action Scheduler job' );
		$this->assertFalse( wp_next_scheduled( self::LOCK, array_values( $sweep ) ) );
	}

	public function test_async_once_does_not_count_a_wp_cron_fallback_once_action_scheduler_is_ready(): void {
		$args = array( 777 );
		wp_schedule_single_event( time(), self::LOCK, $args ); // Left by an early request.
		Queue::async_once( self::LOCK, $args );
		$this->assertTrue( (bool) as_get_scheduled_actions( array( 'hook' => self::LOCK, 'args' => $args, 'status' => 'pending' ), 'ids' ) );
	}
}
