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
	 * Opting in removes every jt_ table, every jetonomy_ capability, the
	 * plugin's own WP-Cron jobs (named as the plugin schedules them today), its
	 * Action Scheduler rows, the licence check and the widgets' saved settings.
	 */
	public function test_opt_in_removes_cron_jobs_and_widget_settings(): void {
		global $wpdb;
		$snapshot = $this->snapshot_options();
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		$has_as   = function_exists( 'as_schedule_single_action' ) && $as_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) );
		if ( $has_as ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, 'jetonomy_uninstall_probe' );
			as_schedule_single_action( time() + HOUR_IN_SECONDS, 'jetonomy_pro_uninstall_probe' );
		}
		update_option( 'jetonomy_settings', array( 'remove_data_on_uninstall' => 1 ) );
		update_option( 'widget_jetonomy_leaderboard', array( '_multiwidget' => 1 ) );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'jetonomy_trust_evaluation' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'edd_sl_sdk_weekly_license_check_jetonomy' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'jetonomy_pro_digest_daily' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'jetonomy/jetonomy.php' );
		}
		// The test case turns DROP TABLE into DROP TEMPORARY TABLE, which would
		// leave the real tables in place; let uninstall drop them for real (the
		// finally block creates them again).
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			include JETONOMY_DIR . 'uninstall.php';
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

			$this->assertFalse( wp_next_scheduled( 'jetonomy_trust_evaluation' ), 'plugin cron job cleared' );
			$this->assertFalse( wp_next_scheduled( 'edd_sl_sdk_weekly_license_check_jetonomy' ), 'licence check cleared' );
			$this->assertFalse( get_option( 'widget_jetonomy_leaderboard' ), 'widget settings removed' );
			$this->assertNotFalse( wp_next_scheduled( 'jetonomy_pro_digest_daily' ), "Pro's jobs are left to Pro's uninstall" );

			foreach ( \Jetonomy\DB\Schema::get_table_names() as $table ) {
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ), "{$table} dropped" );
			}
			foreach ( wp_roles()->roles as $slug => $role ) {
				$left = array_filter( array_keys( (array) ( $role['capabilities'] ?? array() ) ), static fn( $cap ) => str_starts_with( (string) $cap, 'jetonomy_' ) );
				$this->assertSame( array(), array_values( $left ), "no jetonomy_ capability left on {$slug}" );
			}
			if ( $has_as ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$hooks = $wpdb->get_col( $wpdb->prepare( "SELECT hook FROM {$as_table} WHERE hook IN (%s, %s)", 'jetonomy_uninstall_probe', 'jetonomy_pro_uninstall_probe' ) );
				$this->assertNotContains( 'jetonomy_uninstall_probe', $hooks, "Jetonomy's Action Scheduler rows removed" );
				$this->assertContains( 'jetonomy_pro_uninstall_probe', $hooks, "Pro's Action Scheduler rows left to Pro's uninstall" );
			}
		} finally {
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			if ( $has_as ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$as_table} WHERE hook IN (%s, %s)", 'jetonomy_uninstall_probe', 'jetonomy_pro_uninstall_probe' ) );
			}
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
