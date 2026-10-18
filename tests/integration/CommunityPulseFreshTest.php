<?php
/**
 * The home welcome's member and topic counts follow a recount, import or demo
 * seed instead of showing the old numbers for up to an hour.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;

class CommunityPulseFreshTest extends WP_UnitTestCase {

	public function test_a_recount_refreshes_the_home_counts(): void {
		\Jetonomy\DB\Schema::create_tables();
		self::factory()->user->create_many( 2 );
		\Jetonomy\Models\UserProfile::find_or_create( self::factory()->user->create() );
		set_transient( 'jetonomy_community_pulse', array( 'posts' => 0, 'members' => 0, 'posts_week' => 0 ), HOUR_IN_SECONDS );

		\Jetonomy\Recount::run( 'all' );

		$this->assertGreaterThan( 0, jetonomy_community_pulse()['members'], 'members counted from the tables, not the stale transient' );
	}
}
