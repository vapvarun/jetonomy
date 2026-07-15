<?php
/**
 * Cache wrapper — delete_many + flush guard.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Cache;

use WP_UnitTestCase;
use Jetonomy\Cache;

/**
 * @group cache
 */
class CacheWrapperTest extends WP_UnitTestCase {

	public function test_delete_many_removes_every_key(): void {
		Cache::set( 'a', 1 );
		Cache::set( 'b', 2 );

		Cache::delete_many( [ 'a', 'b' ] );

		$this->assertFalse( Cache::get( 'a' ), 'key a should be gone' );
		$this->assertFalse( Cache::get( 'b' ), 'key b should be gone' );
	}

	public function test_delete_many_ignores_empty_input(): void {
		Cache::set( 'keep', 9 );
		Cache::delete_many( [] );
		$this->assertSame( 9, Cache::get( 'keep' ) );
	}

	public function test_flush_does_not_fatal_without_persistent_cache(): void {
		// On the default (request-local) cache flush is a no-op; it must never fatal.
		Cache::set( 'x', 1 );
		Cache::flush();
		$this->assertTrue( true );
	}
}
