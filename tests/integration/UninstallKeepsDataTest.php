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
		$snapshot = $this->snapshot_options();
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
			// DROP TABLE commits the test transaction, so the stripped roles and
			// deleted options would outlive the rollback and break later tests
			// once WordPress reloads roles. Put them back before CREATE TABLE
			// commits again.
			$this->restore_options( $snapshot );
			\Jetonomy\DB\Schema::create_tables();
		}
	}

	/**
	 * The plugin's options and the stored role map, as raw rows.
	 *
	 * @return array<int, array{option_name: string, option_value: string, autoload: string}>
	 */
	private function snapshot_options(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s",
				$wpdb->esc_like( 'jetonomy' ) . '%',
				$wpdb->prefix . 'user_roles'
			),
			ARRAY_A
		);
	}

	/**
	 * Write the snapshot back and reload roles from it.
	 *
	 * @param array<int, array{option_name: string, option_value: string, autoload: string}> $rows Snapshot rows.
	 */
	private function restore_options( array $rows ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'jetonomy' ) . '%' ) );
		foreach ( $rows as $row ) {
			$wpdb->replace( $wpdb->options, $row );
		}
		wp_cache_flush();
		wp_roles()->for_site();
	}
}
