<?php
/**
 * Notification counters — every named write path busts notif:unread:{id} +
 * notif:counts:{id} within the same request (caching plan WP4.7).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Models\Notification;
use Jetonomy\Models\BlockedUser;

/**
 * @group cache
 */
class NotificationCountCacheTest extends WP_UnitTestCase {

	public function test_create_and_reads_reflect_immediately(): void {
		$uid = self::factory()->user->create();

		$this->assertSame( 0, Notification::unread_count( $uid ) ); // primes 0

		$nid = Notification::create(
			[
				'user_id' => $uid,
				'type'    => 'mention',
			]
		);
		$this->assertGreaterThan( 0, $nid );
		$this->assertSame( 1, Notification::unread_count( $uid ), 'create() must bust the cached 0' );

		Notification::mark_read( $nid );
		$this->assertSame( 0, Notification::unread_count( $uid ), 'mark_read() must bust' );
	}

	public function test_bulk_paths_bust(): void {
		$uid  = self::factory()->user->create();
		$nid1 = Notification::create( [ 'user_id' => $uid, 'type' => 'mention' ] );
		$nid2 = Notification::create( [ 'user_id' => $uid, 'type' => 'mention' ] );

		$this->assertSame( 2, Notification::unread_count( $uid ) );
		$this->assertSame( 2, Notification::counts_by_filter( $uid )['unread'] ); // primes counts

		Notification::mark_read_for_user( $uid, [ $nid1 ] );
		$this->assertSame( 1, Notification::unread_count( $uid ), 'mark_read_for_user() must bust' );
		$this->assertSame( 1, Notification::counts_by_filter( $uid )['unread'] );

		Notification::mark_all_read( $uid );
		$this->assertSame( 0, Notification::unread_count( $uid ), 'mark_all_read() must bust' );

		Notification::delete_for_user( $uid, [ $nid1, $nid2 ] );
		$this->assertSame( 0, Notification::counts_by_filter( $uid )['all'], 'delete_for_user() must bust' );
	}

	public function test_blocking_busts_the_counters(): void {
		$uid   = self::factory()->user->create();
		$actor = self::factory()->user->create();

		Notification::create(
			[
				'user_id'  => $uid,
				'actor_id' => $actor,
				'type'     => 'mention',
			]
		);
		$this->assertSame( 1, Notification::unread_count( $uid ) ); // primes with the actor visible

		BlockedUser::block( $uid, $actor );
		$this->assertSame(
			0,
			Notification::unread_count( $uid ),
			'BlockedUser::bust_cache() must bust the counters — they apply the block exclusion'
		);
	}
}
