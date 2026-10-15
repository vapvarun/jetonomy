<?php
/**
 * Demo import must not stack badge awards, and removal must not orphan them
 * (Basecamp 10410707446).
 *
 * Every import awarded the site admin "First Post" and "Early Adopter" again,
 * because the award loop ran over admin + members with no "already held"
 * check. Removal then deleted awards by demo user id only, so the admin's
 * copies outlived the badges they pointed at.
 *
 * The badge tables belong to Pro; they are created here as real tables (the
 * seeder looks them up with SHOW TABLES, which cannot see the suite's
 * temporary tables) and dropped again in tear_down.
 *
 * @package Jetonomy\Tests\ErrorPaths
 */

namespace Jetonomy\Tests\ErrorPaths;

use WP_UnitTestCase;
use Jetonomy\Admin\Ajax\Demo_Seeder;
use Jetonomy\DB\Schema;

class DemoBadgeAwardsTest extends WP_UnitTestCase {

	private string $badges;
	private string $awards;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wpdb;
		$this->badges = $wpdb->prefix . 'jt_pro_badges';
		$this->awards = $wpdb->prefix . 'jt_pro_user_badges';

		$this->real_tables(
			static function () use ( $wpdb ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_pro_badges, {$wpdb->prefix}jt_pro_user_badges" );
				$wpdb->query( "CREATE TABLE {$wpdb->prefix}jt_pro_badges ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(100) NOT NULL, slug varchar(100) NOT NULL, description text, icon varchar(50) NOT NULL DEFAULT '', tier varchar(10) NOT NULL DEFAULT 'bronze', category varchar(20) NOT NULL DEFAULT 'participation', criteria longtext NOT NULL, reputation_bonus int NOT NULL DEFAULT 0, is_repeatable tinyint(1) NOT NULL DEFAULT 0, max_awards int NOT NULL DEFAULT 0, is_active tinyint(1) NOT NULL DEFAULT 1, created_at datetime NULL, PRIMARY KEY (id), UNIQUE KEY slug (slug) )" );
				$wpdb->query( "CREATE TABLE {$wpdb->prefix}jt_pro_user_badges ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL DEFAULT 0, badge_id bigint(20) unsigned NOT NULL DEFAULT 0, earned_at datetime NULL, metadata longtext, PRIMARY KEY (id), KEY idx_badge (badge_id) )" );
				// phpcs:enable
			}
		);
	}

	public function tear_down(): void {
		global $wpdb;
		$this->real_tables(
			static function () use ( $wpdb ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_pro_badges, {$wpdb->prefix}jt_pro_user_badges" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
		parent::tear_down();
	}

	/**
	 * Run $fn with the suite's CREATE/DROP-to-TEMPORARY rewrite switched off.
	 *
	 * @param callable $fn Work to run.
	 */
	private function real_tables( callable $fn ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$fn();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_import_awards_members_once_and_removal_leaves_no_orphans(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		Demo_Seeder::import( $admin_id );

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->awards} WHERE user_id = %d", $admin_id ) ), 'The site admin is not a demo member and gets no demo badges.' );
		$dupes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT user_id, badge_id FROM {$this->awards} GROUP BY user_id, badge_id HAVING COUNT(*) > 1 ) d" );
		$this->assertSame( 0, $dupes, 'No member holds a non-repeatable badge twice.' );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->awards}" ) );

		$this->assertTrue( Demo_Seeder::remove() );

		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->awards} a LEFT JOIN {$this->badges} b ON b.id = a.badge_id WHERE b.id IS NULL" );
		$this->assertSame( 0, $orphans, 'Removing the demo badges removes every award of them.' );
		// phpcs:enable
	}
}
