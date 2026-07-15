<?php
/**
 * UserProfile cache — writers bust profile:{id} after the write.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Models\UserProfile;

/**
 * @group cache
 */
class ProfileCacheTest extends WP_UnitTestCase {

	public function test_update_profile_reflects_after_the_write(): void {
		$uid = self::factory()->user->create();
		UserProfile::find_by_user( $uid ); // prime

		$bio = 'cache-t3-' . uniqid();
		UserProfile::update_profile( $uid, [ 'bio' => $bio ] );

		$this->assertSame( $bio, UserProfile::find_by_user( $uid )->bio );
	}

	public function test_reputation_delta_reflects_after_the_write(): void {
		$uid = self::factory()->user->create();
		UserProfile::find_by_user( $uid ); // prime
		$before = (int) ( UserProfile::find_by_user( $uid )->reputation ?? 0 );

		UserProfile::_apply_reputation_delta( $uid, 5 );

		$this->assertSame( $before + 5, (int) UserProfile::find_by_user( $uid )->reputation );
	}

	public function test_increment_counts_reflect_after_the_write(): void {
		$uid = self::factory()->user->create();
		UserProfile::find_by_user( $uid ); // prime
		$before = (int) ( UserProfile::find_by_user( $uid )->reply_count ?? 0 );

		UserProfile::increment_reply_count( $uid, 1 );

		$this->assertSame( $before + 1, (int) UserProfile::find_by_user( $uid )->reply_count );
	}
}
