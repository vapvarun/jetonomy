<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\UserProfile;

/**
 * One definition of "member" for every "N members" figure: the home pulse said
 * 26 while the leaderboard's "Your rank #N of M" said 25 on the same site,
 * because the pulse counted every profile row, including one whose WP user was
 * deleted and one banned site-wide.
 */
class MemberCountTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		delete_transient( 'jetonomy_community_pulse' );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \Jetonomy\table( 'user_profiles' ) ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . \Jetonomy\table( 'restrictions' ) ); // phpcs:ignore
	}

	public function tear_down(): void {
		delete_transient( 'jetonomy_community_pulse' );
		parent::tear_down();
	}

	public function test_deleted_and_banned_profiles_are_not_members_and_the_pulse_agrees(): void {
		global $wpdb;
		foreach ( array( 1, 2, 3 ) as $_ ) {
			UserProfile::find_or_create( $this->factory()->user->create() );
		}

		// A profile left behind by a deleted WP user.
		$gone = $this->factory()->user->create();
		UserProfile::find_or_create( $gone );
		$wpdb->delete( $wpdb->users, array( 'ID' => $gone ) ); // phpcs:ignore

		// A member banned site-wide.
		$banned = $this->factory()->user->create();
		UserProfile::find_or_create( $banned );
		$wpdb->insert( // phpcs:ignore
			\Jetonomy\table( 'restrictions' ),
			array(
				'user_id'    => $banned,
				'type'       => 'global_ban',
				'created_at' => current_time( 'mysql', true ),
			)
		);

		$this->assertSame( 3, UserProfile::count_members() );
		$this->assertSame( UserProfile::count_members(), (int) jetonomy_community_pulse()['members'] );
	}
}
