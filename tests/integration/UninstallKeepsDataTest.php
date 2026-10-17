<?php
/**
 * Deleting the plugin keeps the community unless the owner opted in to
 * removal (Settings > General > Data).
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;

class UninstallKeepsDataTest extends WP_UnitTestCase {

	/**
	 * uninstall.php strips role caps from the shared in-memory roles; give them
	 * back so later tests still post as members.
	 */
	public function tear_down(): void {
		\Jetonomy\Permissions\Capabilities::register();
		parent::tear_down();
	}

	public function test_uninstall_keeps_tables_and_settings_by_default(): void {
		global $wpdb;
		update_option( 'jetonomy_settings', array( 'base_slug' => 'community' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'jetonomy/jetonomy.php' );
		}
		include JETONOMY_DIR . 'uninstall.php';

		$this->assertSame( 'community', get_option( 'jetonomy_settings' )['base_slug'] ?? null, 'settings kept' );
		$this->assertNotEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'jt_posts' ) ), 'tables kept' );
	}

	/**
	 * Opting in removes the plugin's own WP-Cron jobs (named as the plugin
	 * schedules them today), the licence check and the widgets' saved settings.
	 */
	public function test_opt_in_removes_cron_jobs_and_widget_settings(): void {
		update_option( 'jetonomy_settings', array( 'remove_data_on_uninstall' => 1 ) );
		update_option( 'widget_jetonomy_leaderboard', array( '_multiwidget' => 1 ) );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'jetonomy_trust_evaluation' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'edd_sl_sdk_weekly_license_check_jetonomy' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'jetonomy_pro_digest_daily' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'jetonomy/jetonomy.php' );
		}
		try {
			include JETONOMY_DIR . 'uninstall.php';

			$this->assertFalse( wp_next_scheduled( 'jetonomy_trust_evaluation' ), 'plugin cron job cleared' );
			$this->assertFalse( wp_next_scheduled( 'edd_sl_sdk_weekly_license_check_jetonomy' ), 'licence check cleared' );
			$this->assertFalse( get_option( 'widget_jetonomy_leaderboard' ), 'widget settings removed' );
			$this->assertNotFalse( wp_next_scheduled( 'jetonomy_pro_digest_daily' ), "Pro's jobs are left to Pro's uninstall" );
		} finally {
			wp_clear_scheduled_hook( 'jetonomy_pro_digest_daily' );
			// DROP TABLE is not rolled back with the test transaction.
			\Jetonomy\DB\Schema::create_tables();
		}
	}
}
