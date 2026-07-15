<?php
/**
 * Privileged-member list uses the object cache, not a transient.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Cache;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * @group cache
 */
class PrivilegedMembersCacheTest extends WP_UnitTestCase {

	public function test_uses_object_cache_and_writes_no_transient(): void {
		$uid = self::factory()->user->create();
		$id  = Space::create(
			[
				'title'       => 'PrivCache',
				'slug'        => 'priv-cache-' . uniqid(),
				'visibility'  => 'public',
				'category_id' => 0,
			],
			0
		);
		SpaceMember::add( $id, $uid, 'admin' );

		SpaceMember::list_privileged( $id ); // prime

		$this->assertFalse( get_transient( "jt_priv_members_{$id}" ), 'still writing a transient' );
		$this->assertNotFalse( Cache::get( "priv_members_{$id}" ), 'not in object cache' );
	}

	public function test_bust_clears_the_object_cache(): void {
		$uid = self::factory()->user->create();
		$id  = Space::create(
			[
				'title'       => 'PrivCache2',
				'slug'        => 'priv-cache2-' . uniqid(),
				'visibility'  => 'public',
				'category_id' => 0,
			],
			0
		);
		SpaceMember::add( $id, $uid, 'admin' );
		SpaceMember::list_privileged( $id ); // prime

		SpaceMember::bust_privileged_cache( $id );

		$this->assertFalse( Cache::get( "priv_members_{$id}" ) );
	}
}
