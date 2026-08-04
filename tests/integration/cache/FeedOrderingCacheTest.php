<?php
/**
 * Feed ordering cache — the cached id list must NEVER serve a post the live
 * visibility predicate would exclude (caching plan WP4.5: cache the ordering,
 * re-apply the full gate on hydration). The safety review's concrete leak:
 * prime the cache, privatize/trash a listed post, re-read inside the TTL.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;

/**
 * @group cache
 */
class FeedOrderingCacheTest extends WP_UnitTestCase {

	private function make_space_and_post(): array {
		$space_id = Space::create(
			[
				'title'      => 'Feed cache space ' . uniqid(),
				'slug'       => 'feed-cache-' . uniqid(),
				'visibility' => 'public',
				'status'     => 'active',
			],
			0
		);
		$post_id  = Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => self::factory()->user->create(),
				'title'     => 'Feed cache probe ' . uniqid(),
				'content'   => 'probe',
				'status'    => 'publish',
			]
		);

		// Rank the probe first under BOTH hot and top ordering — the shared
		// test DB accumulates rows across runs (DDL commits break rollback),
		// so a zero-vote probe can fall below the 50-item page and the test
		// would flake on database history instead of testing the cache.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'jt_posts',
			[ 'vote_score' => 99999 ],
			[ 'id' => $post_id ]
		);

		return [ $space_id, $post_id ];
	}

	public function test_privatized_post_drops_within_ttl(): void {
		[ , $post_id ] = $this->make_space_and_post();

		// Prime the guest hot-feed cache — the probe post must be in it.
		$first = Post::list_global_feed( 0, 'hot', 50, 0 );
		$ids   = array_map( static fn( $p ) => (int) $p->id, $first['posts'] );
		$this->assertContains( $post_id, $ids, 'probe post missing from the primed feed' );

		// Author flips the post private. The id list is still cached.
		Post::update( $post_id, [ 'is_private' => 1 ] );

		$second = Post::list_global_feed( 0, 'hot', 50, 0 );
		$ids2   = array_map( static fn( $p ) => (int) $p->id, $second['posts'] );
		$this->assertNotContains(
			$post_id,
			$ids2,
			'LEAK: a just-privatized post served from the cached feed ordering'
		);
	}

	public function test_trashed_post_drops_within_ttl(): void {
		[ , $post_id ] = $this->make_space_and_post();

		$first = Post::list_global_feed( 0, 'top', 50, 0, 7 );
		$ids   = array_map( static fn( $p ) => (int) $p->id, $first['posts'] );
		$this->assertContains( $post_id, $ids, 'probe post missing from the primed top feed' );

		Post::update( $post_id, [ 'status' => 'trash' ] );

		$second = Post::list_global_feed( 0, 'top', 50, 0, 7 );
		$ids2   = array_map( static fn( $p ) => (int) $p->id, $second['posts'] );
		$this->assertNotContains(
			$post_id,
			$ids2,
			'LEAK: a trashed post served from the cached feed ordering'
		);
	}
}
