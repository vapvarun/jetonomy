<?php
/**
 * Verifies multisite-aware activation and new-blog table creation for Jetonomy Pro.
 *
 * On a multisite install, network-activating Jetonomy Pro must create Pro tables
 * on every existing blog. New blogs added after activation must also get Pro tables
 * via the wp_initialize_site hook, so Pro features never hit "table doesn't exist"
 * on those blogs.
 *
 * Both tests are skipped when the test bootstrap is not running against a
 * multisite stack -- they cannot pass in single-site mode and are not expected to.
 * Both tests are also skipped when Jetonomy Pro is not loaded.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy_Pro\activate_plugin
 * @covers \Jetonomy_Pro\install_on_new_site
 */
class ProMultisiteInstallTest extends WP_UnitTestCase {

	public function test_pro_network_activation_runs_install_on_all_blogs(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active -- Pro multisite tests skipped.' );
		}
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite test bootstrap' );
		}
		$blog_id = wpmu_create_blog( 'example.test', '/site-pro/', 'Pro Site', 1 );
		$this->assertIsInt( $blog_id );

		\Jetonomy_Pro\activate_plugin( true );

		switch_to_blog( $blog_id );
		global $wpdb;
		$sample = $wpdb->prefix . 'jt_pro_messages';
		$this->assertSame( $sample, $wpdb->get_var( "SHOW TABLES LIKE '{$sample}'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		restore_current_blog();
	}

	public function test_new_blog_install_creates_pro_tables_via_wp_initialize_site(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active -- Pro multisite tests skipped.' );
		}
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite test bootstrap' );
		}
		$blog_id = wpmu_create_blog( 'example.test', '/site-pro2/', 'Pro Site 2', 1 );
		do_action( 'wp_initialize_site', get_site( $blog_id ), array() );

		switch_to_blog( $blog_id );
		global $wpdb;
		$sample = $wpdb->prefix . 'jt_pro_messages';
		$this->assertSame( $sample, $wpdb->get_var( "SHOW TABLES LIKE '{$sample}'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		restore_current_blog();
	}
}
