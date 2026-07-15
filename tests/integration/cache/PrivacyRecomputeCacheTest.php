<?php
/**
 * §4d — the GDPR/user-delete member_count recompute busts each affected space.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use ReflectionMethod;
use Jetonomy\Privacy;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use function Jetonomy\table;

/**
 * @group cache
 */
class PrivacyRecomputeCacheTest extends WP_UnitTestCase {

	public function test_purge_recompute_busts_cached_member_count(): void {
		global $wpdb;

		$id = Space::create(
			[
				'title'       => 'PrivacyCache',
				'slug'        => 'privacy-cache-' . uniqid(),
				'visibility'  => 'public',
				'category_id' => 0,
			],
			0
		);
		SpaceMember::add( $id, 2, 'member' );

		$sp = table( 'spaces' );
		// Drift the DB value WITHOUT going through the model, so the cache is not busted.
		$wpdb->query( $wpdb->prepare( "UPDATE {$sp} SET member_count = 999 WHERE id = %d", $id ) ); // phpcs:ignore
		Space::find( $id ); // primes the cache with the drifted 999

		$real = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . table( 'space_members' ) . ' WHERE space_id = %d', $id )
		);

		$method = new ReflectionMethod( Privacy::class, 'recompute_counters_after_purge' );
		$method->setAccessible( true );
		$method->invoke( new Privacy(), [], [ $id ] );

		$this->assertSame(
			$real,
			(int) Space::find( $id )->member_count,
			'cached member_count stayed stale after the set-based recompute (§4d)'
		);
	}
}
