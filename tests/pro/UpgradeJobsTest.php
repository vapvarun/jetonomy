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

	/** The webhooks extension's forwarding callback, as its boot() adds it when the extension is on. */
	private function forward_webhooks(): void {
		$ext = new \Jetonomy_Pro\Extensions\Webhooks\Extension();
		if ( ! has_action( self::LEGACY ) && method_exists( $ext, 'forward_legacy_delivery' ) ) {
			add_action( self::LEGACY, array( $ext, 'forward_legacy_delivery' ), 10, 5 );
		}
	}

	/**
	 * Insert $count pending 2.0.7 rows of $hook, due now, each with its own webhook id (so duplicates show).
	 */
	private function seed_legacy( int $count ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		as_schedule_single_action( time() - 60, self::LEGACY, array( 'webhook_id' => 1, 'url' => 'https://example.test/x', 'secret' => 's', 'payload' => '{}', 'event' => 'e' ), 'jetonomy' );
		$cols = 'hook, status, scheduled_date_gmt, scheduled_date_local, priority, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args';
		while ( ( $have = count( $this->rows( self::LEGACY ) ) ) < $count ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ( {$cols} ) SELECT {$cols} FROM {$table} WHERE hook = %s LIMIT %d", self::LEGACY, $count - $have ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET args = REPLACE( args, '\"webhook_id\":1,', CONCAT( '\"webhook_id\":', action_id, ',' ) ) WHERE hook = %s", self::LEGACY ) ); // phpcs:ignore WordPress.DB
	}

	/** Webhook ids of every pending delivery. */
	private function delivered_ids(): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT args FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'pending'", self::DELIVER ) ) as $args ) { // phpcs:ignore WordPress.DB
			$out[] = (int) ( json_decode( (string) $args, true )[0] ?? 0 );
		}
		return $out;
	}

	/**
	 * QA J33: a runner working the queue while the upgrade converts it. The runner takes the oldest rows (the legacy
	 * ones) and used to fail them ("no callbacks are registered"); the upgrade then deleted them. Every row must be
	 * delivered exactly once whichever side reaches it.
	 */
	public function test_a_runner_working_during_the_conversion_loses_and_duplicates_nothing(): void {
		global $wpdb;
		$count = 12000;
		$this->forward_webhooks();
		$this->seed_legacy( $count );
		$store  = \ActionScheduler::store();
		$runner = \ActionScheduler::runner();
		$run = static function ( $claim ) use ( $store, $runner ): void {
			foreach ( $claim->get_actions() as $id ) {
				$runner->process_action( $id, 'qa' );
			}
			$store->release_claim( $claim );
		};
		for ( $round = 0; $round < 400; $round++ ) {
			$run( $store->stake_claim( 50, null, array( self::LEGACY ), 'jetonomy' ) ); // The runner reaches old rows first ...
			$claim = $store->stake_claim( 50, null, array( self::LEGACY ), 'jetonomy' ); // ... claims more ...
			Queue::cancel( self::UPGRADE );
			\Jetonomy_Pro::instance()->upgrade_jobs( '2.0.7' ); // ... the upgrade pass runs meanwhile ...
			$run( $claim ); // ... then the runner runs what it claimed.
			if ( ! Queue::is_pending( self::UPGRADE ) && ! $this->rows( self::LEGACY ) ) {
				break;
			}
		}
		$ids = $this->delivered_ids();
		$this->assertCount( $count, $ids, "{$count} deliveries" );
		$this->assertCount( $count, array_unique( $ids ), 'no duplicate' );
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN (%s, %s) AND status = 'failed'", self::LEGACY, self::DELIVER ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $failed, 'nothing failed' );
		$this->assertSame( array(), $this->rows( self::LEGACY ), 'no old row (URL and secret) left' );
		Queue::delete_rows( self::DELIVER );
	}

	/** Rows a runner already failed before this fix are delivered (claim-first, last 7 days), not deleted. */
	public function test_rows_already_failed_with_no_callbacks_are_requeued_before_the_delete(): void {
		$this->forward_webhooks();
		$this->seed_legacy( 30 );
		global $wpdb;
		$logger = \ActionScheduler::logger();
		foreach ( $this->rows( self::LEGACY ) as $id ) {
			\ActionScheduler::store()->mark_failure( (int) $id );
			$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'action_id' => (int) $id ) ); // phpcs:ignore WordPress.DB
			// Half failed for no callback, half by a runner deadlock on the claim: neither ran.
			$logger->log( (int) $id, $id % 2 ? 'Scheduled action for jetonomy_pro_dispatch_webhook will not be executed as no callbacks are registered.' : "action failed via WP CLI: Unable to update the status of action {$id} to in-progress." );
		}
		$this->drain();
		$ids = $this->delivered_ids();
		$this->assertCount( 30, $ids );
		$this->assertCount( 30, array_unique( $ids ) );
		$this->assertSame( array(), $this->rows( self::LEGACY ) );
		Queue::delete_rows( self::DELIVER );
	}

	/** Legacy digest and badge batches a runner reaches first restart as one cursor run each; none fails. */
	public function test_a_runner_reaching_legacy_digest_and_badge_batches_restarts_them(): void {
		require_once JETONOMY_PRO_DIR . 'includes/extensions/email-digest/class-extension.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/custom-badges/class-extension.php';
		// What the two extensions' boot() adds when they are on.
		if ( ! has_action( 'jetonomy_pro_digest_batch' ) ) {
			add_action( 'jetonomy_pro_digest_batch', array( new \Jetonomy_Pro\Extensions\Email_Digest\Extension(), 'forward_legacy_batch' ) );
		}
		if ( ! has_action( 'jetonomy_pro_badges_batch' ) ) {
			add_action( 'jetonomy_pro_badges_batch', array( new \Jetonomy_Pro\Extensions\Custom_Badges\Extension(), 'forward_legacy_batch' ) );
		}
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = (int) as_schedule_single_action( time() - 60, 'jetonomy_pro_digest_batch', array( array( 'frequency' => 'weekly', 'offset' => 50 * $i, 'limit' => 50 ) ), 'jetonomy' );
			$ids[] = (int) as_schedule_single_action( time() - 60, 'jetonomy_pro_badges_batch', array( array( 'offset' => 100 * $i, 'limit' => 100 ) ), 'jetonomy' );
		}
		try {
			foreach ( $ids as $id ) {
				\ActionScheduler::runner()->process_action( $id, 'qa' );
			}
			foreach ( $ids as $id ) {
				$this->assertSame( 'complete', \ActionScheduler::store()->get_status( (string) $id ), 'ran, not failed' );
			}
			$this->assertSame( array( array( 'weekly', 0 ) ), array_map( static fn( $a ) => array_values( $a->get_args() ), array_values( as_get_scheduled_actions( array( 'hook' => 'jetonomy_pro_send_digests', 'status' => 'pending' ), 'objects' ) ) ) );
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => 'jetonomy_pro_evaluate_badges_after', 'status' => 'pending', 'args' => array( 0 ) ), 'ids' ) );
		} finally {
			foreach ( array( 'jetonomy_pro_digest_batch', 'jetonomy_pro_badges_batch', 'jetonomy_pro_send_digests', 'jetonomy_pro_evaluate_badges_after' ) as $hook ) {
				Queue::delete_rows( $hook );
			}
		}
	}

	/**
	 * QA J33: the upgrade runs on plugins_loaded, before Action Scheduler's store is ready, and queued the conversion on
	 * WP-Cron, which waited for a visit while the runner reached the old rows. Now a WP-Cron copy is only the backstop
	 * and is replaced by an Action Scheduler copy once the store is ready.
	 */
	public function test_the_conversion_moves_to_action_scheduler_once_its_store_is_ready(): void {
		global $wp_actions, $wp_filter;
		$pro   = \Jetonomy_Pro::instance();
		$args  = array( '2.0.7' );
		$fired = $wp_actions['action_scheduler_init'] ?? 0;
		$hooks = $wp_filter['action_scheduler_init'] ?? null;
		unset( $wp_filter['action_scheduler_init'] ); // Only what run_upgrade() adds runs below.
		$wp_actions['action_scheduler_init'] = 0; // The first request, before the store is ready.
		try {
			$this->assertTrue( $pro->run_upgrade( '2.0.7' ) );
			$this->assertNotFalse( wp_next_scheduled( self::UPGRADE, $args ), 'a WP-Cron backstop for a request that ends early' );
			$wp_actions['action_scheduler_init'] = $fired;
			do_action( 'action_scheduler_init' ); // The store is ready later in the same request.
		} finally {
			$wp_actions['action_scheduler_init'] = max( 1, (int) $fired );
			if ( null !== $hooks ) {
				$wp_filter['action_scheduler_init'] = $hooks;
			}
		}
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => self::UPGRADE, 'status' => 'pending' ), 'ids' ), 'on Action Scheduler' );
		$this->assertFalse( wp_next_scheduled( self::UPGRADE, $args ), 'the WP-Cron copy is gone' );
		delete_transient( Queue::RECOVER_FLAG );
	}
}

