<?php
/**
 * A demo import that dies part way must stay removable (Basecamp 10375917700).
 *
 * The seed used to save its manifest only at the very end, so a fatal mid-run
 * left the Dashboard on "Import demo data" and the seeded rows with no way out.
 *
 * @package Jetonomy\Tests\ErrorPaths
 */

namespace Jetonomy\Tests\ErrorPaths;

use RuntimeException;
use WP_UnitTestCase;
use Jetonomy\Admin\Ajax\Demo_Seeder;
use Jetonomy\DB\Schema;
use function Jetonomy\table;

class DemoImportCrashTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_crash_mid_seed_leaves_a_removable_manifest(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$replies  = 0;
		$crash    = static function () use ( &$replies ) {
			if ( 300 === ++$replies ) {
				throw new RuntimeException( 'simulated fatal' );
			}
		};
		add_action( 'jetonomy_reply_created', $crash );

		try {
			Demo_Seeder::import( $admin_id );
			$this->fail( 'The simulated fatal did not fire.' );
		} catch ( RuntimeException $e ) {
			remove_action( 'jetonomy_reply_created', $crash );
		}

		$summary = Demo_Seeder::summary();
		$this->assertTrue( $summary['active'], 'A half-seeded set must still show Remove demo data.' );
		$this->assertGreaterThan( 0, $summary['counts']['posts'] );

		$ids = array_map( 'intval', get_option( 'jetonomy_demo_data' )['posts'] );
		$this->assertTrue( Demo_Seeder::remove() );

		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . table( 'posts' ) . " WHERE id IN ($in)" ) );
		$this->assertFalse( Demo_Seeder::summary()['active'] );
	}
}
