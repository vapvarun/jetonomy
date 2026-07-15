<?php
/**
 * Space cache invalidation — id + slug keys, on every writer, after the write.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Models\Space;

/**
 * @group cache
 */
class SpaceCacheTest extends WP_UnitTestCase {

	private function make_space( string $slug, string $visibility = 'public' ): int {
		return Space::create(
			[
				'title'       => 'CacheSpace',
				'slug'        => $slug,
				'visibility'  => $visibility,
				'category_id' => 0,
			],
			0
		);
	}

	public function test_update_busts_the_slug_keyed_row_not_just_the_id_key(): void {
		$slug = 'cache-space-update';
		$id   = $this->make_space( $slug, 'public' );

		Space::find_by_slug( $slug );                 // prime slug->id + space:{id}
		Space::update( $id, [ 'visibility' => 'private' ] );

		$this->assertSame(
			'private',
			Space::find_by_slug( $slug )->visibility,
			'slug-keyed row served stale visibility (J1)'
		);
	}

	public function test_increment_post_count_busts_the_id_key_after_the_write(): void {
		$id     = $this->make_space( 'cache-space-inc' );
		$before = (int) Space::find( $id )->post_count;   // prime

		Space::increment_post_count( $id, 1 );

		$this->assertSame( $before + 1, (int) Space::find( $id )->post_count );
	}

	public function test_delete_busts_both_keys(): void {
		$slug = 'cache-space-del';
		$id   = $this->make_space( $slug );
		Space::find_by_slug( $slug );                 // prime

		Space::delete( $id );

		$this->assertNull( Space::find_by_slug( $slug ), 'deleted space still served from slug cache' );
		$this->assertNull( Space::find( $id ), 'deleted space still served from id cache' );
	}

	public function test_slug_rename_drops_old_and_new_mapping(): void {
		$old = 'cache-space-old';
		$id  = $this->make_space( $old );
		Space::find_by_slug( $old );                  // prime old mapping

		Space::update( $id, [ 'slug' => 'cache-space-new' ] );

		$this->assertNull( Space::find_by_slug( $old ), 'old slug still resolves after rename' );
		$this->assertSame( $id, (int) Space::find_by_slug( 'cache-space-new' )->id );
	}
}
