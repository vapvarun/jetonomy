<?php
/**
 * BuddyNext Pro membership adapter: is_active() asks the database once per request, not once per space card.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy_Pro\Adapters\BuddyNext_Pro_Adapter;

class BuddyNextProAdapterActiveCacheTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_DIR' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded.' );
		}
		require_once JETONOMY_PRO_DIR . 'includes/adapters/class-buddynext-pro-adapter.php';
		if ( ! class_exists( '\BuddyNextPro\Membership\SubscriptionService' ) ) {
			class_alias( self::class, 'BuddyNextPro\Membership\SubscriptionService' ); // Only its presence is read.
		}
		$this->forget();
	}

	public function tear_down(): void {
		$this->forget();
		parent::tear_down();
	}

	private function forget(): void {
		if ( property_exists( BuddyNext_Pro_Adapter::class, 'has_tables' ) ) {
			( new \ReflectionProperty( BuddyNext_Pro_Adapter::class, 'has_tables' ) )->setValue( null, array() );
		}
	}

	/** Answers and the number of SHOW TABLES queries for 30 cards asking. */
	private function ask_30_times(): array {
		$shows   = 0;
		$count   = static function ( string $sql ) use ( &$shows ): string {
			$shows += (int) ( 0 === stripos( ltrim( $sql ), 'SHOW TABLES' ) );
			return $sql;
		};
		$answers = array();
		add_filter( 'query', $count );
		for ( $i = 0; $i < 30; $i++ ) {
			$answers[] = ( new BuddyNext_Pro_Adapter() )->is_active(); // A fresh object per card, as the worst case.
		}
		remove_filter( 'query', $count );
		return array( array_unique( $answers ), $shows );
	}

	public function test_thirty_space_cards_run_the_table_check_once(): void {
		list( $answers, $shows ) = $this->ask_30_times();
		$this->assertSame( 1, $shows );
		$this->assertCount( 1, $answers, 'the same answer every time' );
	}

	public function test_a_site_without_the_tiers_table_is_still_inactive(): void {
		// A site without BuddyNext Pro's tables: the check looks for a table that is not there.
		$missing = static fn( string $sql ): string => str_replace( 'bn_membership_tiers', 'bn_membership_tiers_absent', $sql );
		add_filter( 'query', $missing );
		list( $answers, $shows ) = $this->ask_30_times();
		remove_filter( 'query', $missing );
		$this->assertSame( array( false ), $answers );
		$this->assertSame( 1, $shows );
	}
}
