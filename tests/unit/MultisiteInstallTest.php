<?php
/**
 * Verifies multisite-aware activation and new-blog table creation.
 *
 * On a multisite install, network-activating Jetonomy must create tables on
 * every existing blog. New blogs added after activation must also get tables
 * via the wp_initialize_site hook, so frontend hits on those blogs never see
 * "table doesn't exist".
 *
 * Both tests are skipped when the test bootstrap is not running against a
 * multisite stack -- they cannot pass in single-site mode and are not expected
 * to.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\activate_plugin
 * @covers \Jetonomy\install_on_new_site
 */
class MultisiteInstallTest extends WP_UnitTestCase {

	public function test_network_activation_runs_install_on_all_blogs(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite test bootstrap' );
		}
		$blog_id = wpmu_create_blog( 'example.test', '/site2/', 'Site 2', 1 );
		$this->assertIsInt( $blog_id );

		\Jetonomy\activate_plugin( true );

		switch_to_blog( $blog_id );
		global $wpdb;
		$table = $wpdb->prefix . 'jt_posts';
		$this->assertSame( $table, $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		restore_current_blog();
	}

	public function test_new_blog_install_creates_tables_via_wp_initialize_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite test bootstrap' );
		}
		$blog_id = wpmu_create_blog( 'example.test', '/site3/', 'Site 3', 1 );
		do_action( 'wp_initialize_site', get_site( $blog_id ), array() );

		switch_to_blog( $blog_id );
		global $wpdb;
		$table = $wpdb->prefix . 'jt_posts';
		$this->assertSame( $table, $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		restore_current_blog();
	}
}
