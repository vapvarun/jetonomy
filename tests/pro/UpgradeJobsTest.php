<?php
/**
 * The 2.1.0 upgrade leaves no secret in the job tables, our cancelled rows are pruned, and work queued during the
 * upgrade request becomes an Action Scheduler job rather than a WP-Cron fallback. The upgrade runs once under its
 * lock (a concurrent first request runs no ALTER and queues nothing), stores the version at once, and clears a
 * 12,000-row backlog in bounded background passes.
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
	private const UPGRADE = 'jetonomy_pro_upgrade_jobs';

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) || ! Queue::has_as() ) {
			$this->markTestSkipped( 'Jetonomy Pro or Action Scheduler is not loaded.' );
		}
		require_once JETONOMY_PRO_DIR . 'includes/extensions/webhooks/class-extension.php';
		if ( ! in_array( 'webhooks', \Jetonomy_Pro\Extension::enabled_ids(), true ) ) {
			update_option( 'jetonomy_pro_extensions', array_merge( \Jetonomy_Pro\Extension::enabled_ids(), array( 'webhooks' ) ), false );
		}
		require_once JETONOMY_PRO_DIR . 'includes/extensions/attachments/class-extension.php';
	}

	public function tear_down(): void {
		Queue::set_clock( null );
		foreach ( array( self::LEGACY, self::DELIVER, self::LOCK, self::UPGRADE ) as $hook ) {
			Queue::delete_rows( $hook );
			wp_unschedule_hook( $hook );
		}
		parent::tear_down();
	}

	/**
	 * Run the upgrade job the way Action Scheduler would, pass after pass, until it stops re-queueing itself.
	 *
	 * @return float[] Seconds each pass took.
	 */
	private function drain( string $from = '2.0.7' ): array {
		$passes = array();
		for ( $i = 0; $i < 40; $i++ ) {
			Queue::cancel( self::UPGRADE ); // The copy this pass stands in for.
			$started = microtime( true );
			\Jetonomy_Pro::instance()->upgrade_jobs( $from );
			$passes[] = microtime( true ) - $started;
			if ( ! Queue::is_pending( self::UPGRADE ) ) {
				break;
			}
		}
		return $passes;
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

		$this->drain();

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

	/**
	 * A 2.0.7 row whose stored args JSON has its keys in a different order than a re-encoding (here: a long payload,
	 * so Action Scheduler matches on a hash of the JSON) cannot be cancelled by its arguments. It used to be converted
	 * again on every page of every pass: a full page of them gave ten copies of each delivery and a pass that never ended.
	 */
	public function test_a_row_cancel_by_args_cannot_match_is_converted_once_and_the_pass_ends(): void {
		global $wpdb;
		$limit = Queue::PENDING_READ_LIMIT;
		for ( $i = 1; $i <= $limit; $i++ ) {
			$id   = (int) as_schedule_single_action(
				time() + HOUR_IN_SECONDS,
				self::LEGACY,
				array(
					'webhook_id' => $i,
					'url'        => 'https://example.test/hook',
					'secret'     => 's',
					'payload'    => wp_json_encode( array( 'text' => str_repeat( 'x', 200 ) ) ),
					'event'      => 'qa.event',
				),
				'jetonomy'
			);
			$row  = $wpdb->get_var( $wpdb->prepare( "SELECT extended_args FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) ); // phpcs:ignore WordPress.DB
			$this->assertNotEmpty( $row, 'fixture: long args are matched by hash' );
			$sorted = json_decode( (string) $row, true );
			ksort( $sorted );
			$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'extended_args' => wp_json_encode( $sorted ) ), array( 'action_id' => $id ) ); // phpcs:ignore WordPress.DB
		}

		$more = ( new \Jetonomy_Pro\Extensions\Webhooks\Extension() )->upgrade_jobs( '2.0.7' );

		$this->assertFalse( $more, 'the pass ends' );
		$delivered = as_get_scheduled_actions(
			array(
				'hook'     => self::DELIVER,
				'status'   => 'pending',
				'per_page' => -1,
			),
			'objects'
		);
		$this->assertCount( $limit, $delivered, 'one delivery per 2.0.7 row, not one per page' );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => self::LEGACY, 'status' => 'pending' ), 'ids' ), 'every converted row is cancelled' );
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

	/**
	 * A 12,000-row backlog of the old webhook hook (finished rows plus waiting deliveries) is cleared by the upgrade
	 * job in passes, none longer than the time budget, and a row deleted by another process mid-pass does not throw.
	 * The upgrade itself is run twice, as two first requests after an update would.
	 */
	public function test_a_12000_row_legacy_backlog_is_cleared_in_bounded_background_passes(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$tpl   = (int) as_schedule_single_action( time() - DAY_IN_SECONDS, self::LEGACY, array( 'webhook_id' => 1, 'url' => 'https://example.test/x', 'secret' => 's', 'payload' => '{}', 'event' => 'e' ), 'jetonomy' );
		\ActionScheduler::store()->mark_failure( $tpl );
		$cols = 'hook, status, scheduled_date_gmt, scheduled_date_local, priority, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args';
		while ( count( $this->rows( self::LEGACY ) ) < 12000 ) {
			$need = 12000 - count( $this->rows( self::LEGACY ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ( {$cols} ) SELECT {$cols} FROM {$table} WHERE hook = %s LIMIT %d", self::LEGACY, $need ) ); // phpcs:ignore WordPress.DB
		}
		for ( $i = 0; $i < 3; $i++ ) { // A few deliveries still waiting.
			as_schedule_single_action( time() + HOUR_IN_SECONDS, self::LEGACY, array( 'webhook_id' => 70 + $i, 'url' => 'https://example.test/x', 'secret' => 's', 'payload' => '{"n":' . $i . '}', 'event' => 'e' ), 'jetonomy' );
		}
		$this->assertGreaterThanOrEqual( 12000, count( $this->rows( self::LEGACY ) ) );

		// Another process deletes a row between the read and our delete, once.
		$victim = 0;
		$race   = static function ( $id ) use ( &$victim, $wpdb, $table ): void {
			if ( ! $victim ) {
				$victim = (int) $wpdb->get_var( $wpdb->prepare( "SELECT action_id FROM {$table} WHERE hook = %s AND action_id <> %d ORDER BY action_id DESC LIMIT 1", self::LEGACY, (int) $id ) ); // phpcs:ignore WordPress.DB
				$wpdb->delete( $table, array( 'action_id' => $victim ) ); // phpcs:ignore WordPress.DB
			}
		};
		add_action( 'action_scheduler_deleted_action', $race );
		try {
			$pro = \Jetonomy_Pro::instance();
			$this->assertTrue( $pro->run_upgrade( '2.0.7' ) );
			$this->assertTrue( $pro->run_upgrade( '2.0.7' ) );
			$this->assertSame( JETONOMY_PRO_VERSION, get_option( 'jetonomy_pro_db_version' ), 'the version is stored at once' );
			$passes = $this->drain();
		} finally {
			remove_action( 'action_scheduler_deleted_action', $race );
		}

		$this->assertSame( array(), $this->rows( self::LEGACY ), 'no row of the old hook remains after the chain' );
		$this->assertGreaterThan( 1, count( $passes ), 'more than one pass: no request deleted the whole backlog' );
		foreach ( $passes as $seconds ) {
			$this->assertLessThan( Queue::TIME_BUDGET + 5, $seconds, 'no pass ran past the budget (plus one batch of slack)' );
		}
		$this->assertCount( 3, as_get_scheduled_actions( array( 'hook' => self::DELIVER, 'status' => 'pending', 'per_page' => 10 ), 'ids' ), 'every waiting delivery converted once' );
	}

	/**
	 * Two first requests after an update: while one holds the upgrade lock the other runs no extension activate()
	 * (no ALTER), stores no version and queues no conversion.
	 */
	public function test_a_concurrent_first_request_runs_no_alter_and_queues_nothing(): void {
		$pro   = \Jetonomy_Pro::instance();
		$calls = 0;
		$spy   = new class( $calls ) extends \Jetonomy_Pro\Extension {
			private $calls;
			public function __construct( &$calls ) {
				$this->calls = &$calls;
			}
			public function meta(): array {
				return array( 'id' => 'qa-spy', 'name' => 'QA spy', 'description' => '', 'version' => '1', 'category' => '' );
			}
			public function boot(): void {}
			public function activate(): void {
				++$this->calls;
			}
		};
		$prop = new \ReflectionProperty( $pro, 'extensions' );
		$saved = $prop->getValue( $pro );
		$prop->setValue( $pro, array( 'qa-spy' => $spy ) );
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		try {
			update_option( 'jetonomy_pro_db_version', '2.0.7' );
			\WP_Upgrader::create_lock( 'jetonomy_pro_upgrade', 10 * MINUTE_IN_SECONDS ); // The first request.
			$this->assertFalse( $pro->run_upgrade( '2.0.7' ) );
			$this->assertSame( 0, $calls, 'no activate(), so no ALTER, in the second request' );
			$this->assertSame( '2.0.7', get_option( 'jetonomy_pro_db_version' ) );
			$this->assertFalse( Queue::is_pending( self::UPGRADE ) );

			\WP_Upgrader::release_lock( 'jetonomy_pro_upgrade' );
			$this->assertTrue( $pro->run_upgrade( '2.0.7' ) );
			$this->assertSame( 1, $calls );
			$this->assertSame( JETONOMY_PRO_VERSION, get_option( 'jetonomy_pro_db_version' ) );
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => self::UPGRADE, 'status' => 'pending' ), 'ids' ), 'the conversion is queued once' );
			$this->assertFalse( get_option( 'jetonomy_pro_upgrade.lock' ), 'the lock is released' );
		} finally {
			\WP_Upgrader::release_lock( 'jetonomy_pro_upgrade' );
			$prop->setValue( $pro, $saved );
		}
	}

	/**
	 * The conversion is queued BEFORE the version is stored (a request dying in between leaves the job), a late
	 * request that read the old version queues no second copy, and the upgrade sets the recovery flag (core's
	 * one-click update fires no activation hook).
	 */
	public function test_the_conversion_is_queued_before_the_version_and_only_once(): void {
		$pro     = \Jetonomy_Pro::instance();
		$at_save = null;
		$spy     = function ( $value ) use ( &$at_save ) {
			$at_save = Queue::is_pending( self::UPGRADE );
			return $value;
		};
		update_option( 'jetonomy_pro_db_version', '2.0.6' ); // Older than this build, whatever its header says on a dev branch.
		delete_transient( Queue::RECOVER_FLAG );
		add_filter( 'pre_update_option_jetonomy_pro_db_version', $spy );
		try {
			$this->assertTrue( $pro->run_upgrade( '2.0.7' ) );
		} finally {
			remove_filter( 'pre_update_option_jetonomy_pro_db_version', $spy );
		}
		$this->assertTrue( $at_save, 'the job was pending when the version was written' );
		$this->assertTrue( $pro->run_upgrade( '2.0.7' ), 'a request that read the old version before the lock was released' );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => self::UPGRADE, 'status' => 'pending' ), 'ids' ), 'one conversion job' );
		$this->assertNotFalse( get_transient( Queue::RECOVER_FLAG ), 'the upgrade flags recovery of kept jobs' );
		delete_transient( Queue::RECOVER_FLAG );
	}

	/** Two activations (two upgrade requests) queue one lock sweep: the insert is unique. */
	public function test_activating_attachments_twice_queues_one_lock_sweep(): void {
		$ext = new \Jetonomy_Pro\Extensions\Attachments\Extension();
		if ( ! $ext->is_enabled() ) {
			update_option( 'jetonomy_pro_extensions', array_merge( \Jetonomy_Pro\Extension::enabled_ids(), array( 'attachments' ) ), false );
		}
		Queue::cancel( self::LOCK );
		$ext->activate();
		$ext->activate();
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => self::LOCK, 'args' => array( array( 'sweep' => 1 ) ), 'status' => 'pending' ), 'ids' ) );
	}
}
