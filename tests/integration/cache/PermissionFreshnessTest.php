<?php
/**
 * Permission verdicts are per-request memoized, never persistently cached —
 * a restriction or role change applies to the very next can() in the same
 * process (caching plan WP0.1; replaces the never-busted perm:{} cache).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Cache;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Permission_Engine;

/**
 * @group cache
 */
class PermissionFreshnessTest extends WP_UnitTestCase {

	public function test_ban_denies_within_the_same_request(): void {
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Prime the memo with an allow verdict.
		$this->assertTrue( Permission_Engine::can( $uid, 'create_posts' ) );

		$ban_id = Restriction::ban( $uid, 'global_ban', 1 );
		$this->assertGreaterThan( 0, $ban_id );

		// The ban must be honored immediately — the old perm:{} cache served
		// the stale allow for up to 60s here.
		$this->assertFalse( Permission_Engine::can( $uid, 'create_posts' ) );

		Restriction::remove_ban( $ban_id );
		$this->assertTrue( Permission_Engine::can( $uid, 'create_posts' ) );
	}

	public function test_role_promotion_grants_within_the_same_request(): void {
		$uid      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$space_id = Space::create(
			[
				'title'      => 'Perm memo space ' . uniqid(),
				'slug'       => 'perm-memo-' . uniqid(),
				'visibility' => 'public',
			],
			0
		);
		$this->assertGreaterThan( 0, $space_id );

		// Prime a deny verdict for a moderation action.
		$this->assertFalse( Permission_Engine::can( $uid, 'pin_posts', $space_id ) );

		SpaceMember::add( $space_id, $uid, 'moderator' );

		// Layer-0d mod bypass must apply immediately (bust_privileged_cache
		// clears the verdict memo).
		$this->assertTrue( Permission_Engine::can( $uid, 'pin_posts', $space_id ) );
	}

	public function test_cache_flush_clears_the_memos(): void {
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( Permission_Engine::can( $uid, 'create_posts' ) );

		// Simulate an importer-style raw write the model resets can't see,
		// followed by the one-shot Cache::flush() those paths already call.
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\Jetonomy\table( 'restrictions' ),
			[
				'user_id'    => $uid,
				'type'       => 'global_ban',
				'issued_by'  => 1,
				'created_at' => \Jetonomy\now(),
			]
		);
		Cache::flush();

		$this->assertFalse(
			Permission_Engine::can( $uid, 'create_posts' ),
			'Cache::flush() must reset the verdict + restriction memos (plan U1).'
		);
	}
}
