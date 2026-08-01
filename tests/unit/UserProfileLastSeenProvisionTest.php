<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

/**
 * update_last_seen() must PROVISION a missing profile row (QA 2026-07-30,
 * card 9725751235): it was UPDATE-only, so a member who only ever read the
 * community never got a row - is_online() stayed false forever and the
 * "profile created on first visit" promise was false.
 */
class UserProfileLastSeenProvisionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_update_last_seen_provisions_missing_profile(): void {
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// A pure lurker: no profile row exists yet.
		$wpdb->delete( $wpdb->prefix . 'jt_user_profiles', array( 'user_id' => $user_id ) );
		delete_transient( 'jetonomy_seen_' . $user_id );
		$this->assertNull( UserProfile::find_by_user( $user_id ), 'precondition: no row' );

		// The Template_Loader visit hook calls exactly this.
		UserProfile::update_last_seen( $user_id );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertNotNull( $profile, 'first community visit must create the profile row' );
		$this->assertNotEmpty( $profile->last_seen_at, 'and stamp last_seen_at in the same visit' );
	}

	public function test_throttle_still_prevents_per_request_writes(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		delete_transient( 'jetonomy_seen_' . $user_id );

		UserProfile::update_last_seen( $user_id );
		$first = UserProfile::find_by_user( $user_id )->last_seen_at;

		// Within the minute window the transient short-circuits everything.
		UserProfile::update_last_seen( $user_id );
		$this->assertSame( $first, UserProfile::find_by_user( $user_id )->last_seen_at );
	}
}
