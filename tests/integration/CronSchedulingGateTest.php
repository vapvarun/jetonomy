<?php
/**
 * Recurring jobs are checked only in cron, wp-admin and WP-CLI, never on an
 * ordinary page, REST or AJAX request (each check is a database query).
 *
 * @package Jetonomy\Tests
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\Cron;

/**
 * @covers \Jetonomy\Cron::ensure_scheduled
 */
class CronSchedulingGateTest extends WP_UnitTestCase {

	/**
	 * Ordinary request: no queries, nothing armed. Cron runner: armed.
	 *
	 * @return void
	 */
	public function test_schedules_are_checked_only_in_cron_context(): void {
		as_unschedule_all_actions( 'jetonomy_trust_evaluation', array(), 'jetonomy' );
		global $wpdb;

		$before = $wpdb->num_queries;
		Cron::ensure_scheduled();
		$this->assertSame( $before, $wpdb->num_queries, 'No database work on an ordinary request.' );
		$this->assertFalse( as_has_scheduled_action( 'jetonomy_trust_evaluation', array(), 'jetonomy' ) );

		add_filter( 'wp_doing_cron', '__return_true' );
		Cron::ensure_scheduled();
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertTrue( as_has_scheduled_action( 'jetonomy_trust_evaluation', array(), 'jetonomy' ), 'The cron runner arms it.' );
	}
}
