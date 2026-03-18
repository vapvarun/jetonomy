<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

class UserProfileTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_find_or_create_creates_profile_if_missing(): void {
		$user_id = $this->factory()->user->create();
		$profile = UserProfile::find_or_create( $user_id );
		$this->assertIsObject( $profile );
		$this->assertEquals( $user_id, (int) $profile->user_id );
	}

	public function test_find_or_create_returns_existing_profile(): void {
		$user_id  = $this->factory()->user->create();
		$profile1 = UserProfile::find_or_create( $user_id );
		$profile2 = UserProfile::find_or_create( $user_id );

		// Should be the same record.
		$this->assertEquals( (int) $profile1->user_id, (int) $profile2->user_id );
	}

	public function test_find_by_user_returns_null_for_missing(): void {
		$this->assertNull( UserProfile::find_by_user( 999999 ) );
	}

	public function test_find_by_user_returns_profile(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$profile = UserProfile::find_by_user( $user_id );
		$this->assertNotNull( $profile );
		$this->assertEquals( $user_id, (int) $profile->user_id );
	}

	public function test_update_profile(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::update_profile( $user_id, [ 'bio' => 'I write code.' ] );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 'I write code.', $profile->bio );
	}

	public function test_update_profile_returns_true(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$result = UserProfile::update_profile( $user_id, [ 'bio' => 'Updated.' ] );
		$this->assertTrue( $result );
	}

	public function test_adjust_reputation_positive(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::adjust_reputation( $user_id, 10 );
		UserProfile::adjust_reputation( $user_id, 5 );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 15, (int) $profile->reputation );
	}

	public function test_adjust_reputation_negative(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::adjust_reputation( $user_id, 20 );
		UserProfile::adjust_reputation( $user_id, -5 );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 15, (int) $profile->reputation );
	}

	public function test_increment_post_count(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::increment_post_count( $user_id );
		UserProfile::increment_post_count( $user_id );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 2, (int) $profile->post_count );
	}

	public function test_increment_reply_count(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::increment_reply_count( $user_id );
		UserProfile::increment_reply_count( $user_id );
		UserProfile::increment_reply_count( $user_id );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 3, (int) $profile->reply_count );
	}

	public function test_get_settings_returns_decoded_array(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::update_profile( $user_id, [
			'settings' => json_encode( [ 'email_notifications' => false, 'theme' => 'dark' ] ),
		] );

		$settings = UserProfile::get_settings( $user_id );
		$this->assertIsArray( $settings );
		$this->assertFalse( $settings['email_notifications'] );
		$this->assertEquals( 'dark', $settings['theme'] );
	}

	public function test_get_settings_returns_empty_when_null(): void {
		$user_id  = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$settings = UserProfile::get_settings( $user_id );
		$this->assertIsArray( $settings );
		$this->assertEmpty( $settings );
	}

	public function test_new_profile_has_zero_reputation(): void {
		$user_id = $this->factory()->user->create();
		$profile = UserProfile::find_or_create( $user_id );
		$this->assertEquals( 0, (int) $profile->reputation );
	}

	public function test_new_profile_has_zero_trust_level(): void {
		$user_id = $this->factory()->user->create();
		$profile = UserProfile::find_or_create( $user_id );
		$this->assertEquals( 0, (int) $profile->trust_level );
	}
}
