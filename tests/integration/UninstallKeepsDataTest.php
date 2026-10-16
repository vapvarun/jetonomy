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
}
