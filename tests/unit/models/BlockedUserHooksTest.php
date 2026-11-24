<?php
/**
 * Blocking is announced once per block, not once per request.
 *
 * BlockedUser::block() used INSERT IGNORE and fired jetonomy_user_blocked whether or not a row was stored, so 20 parallel
 * requests (or a repeat) announced one block 12 times. The hooks now follow the rows that really changed.
 *
 * @package Jetonomy\Tests
 */

namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\BlockedUser;

class BlockedUserHooksTest extends WP_UnitTestCase {

	private int $blocker;
	private int $blocked;
	private int $blocks = 0;
	private int $unblocks = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->blocker  = $this->factory()->user->create();
		$this->blocked  = $this->factory()->user->create();
		$this->blocks   = 0;
		$this->unblocks = 0;
		add_action( 'jetonomy_user_blocked', function (): void { ++$this->blocks; } );
		add_action( 'jetonomy_user_unblocked', function (): void { ++$this->unblocks; } );
	}

	private function rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_blocked_users WHERE blocker_id = %d AND blocked_id = %d", $this->blocker, $this->blocked ) );
	}

	public function test_a_block_and_its_repeat_announce_once_and_both_succeed(): void {
		$this->assertTrue( BlockedUser::block( $this->blocker, $this->blocked ) );
		$this->assertTrue( BlockedUser::block( $this->blocker, $this->blocked ), 'Blocking someone already blocked is still a success.' );

		$this->assertSame( 1, $this->rows() );
		$this->assertSame( 1, $this->blocks );
	}

	public function test_a_block_that_loses_to_a_parallel_one_announces_nothing(): void {
		global $wpdb;
		$done = false;
		add_filter(
			'query',
			function ( $sql ) use ( &$done, $wpdb ) {
				if ( ! $done && 0 === stripos( ltrim( $sql ), 'INSERT IGNORE' ) && false !== strpos( $sql, $wpdb->prefix . 'jt_blocked_users' ) ) {
					$done = true;
					$wpdb->insert( $wpdb->prefix . 'jt_blocked_users', array( 'blocker_id' => $this->blocker, 'blocked_id' => $this->blocked, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				}
				return $sql;
			}
		);

		$this->assertTrue( BlockedUser::block( $this->blocker, $this->blocked ) );

		$this->assertSame( 1, $this->rows() );
		$this->assertSame( 0, $this->blocks, 'The request that stored the block announces it.' );
	}

	public function test_an_unblock_and_its_repeat_announce_once(): void {
		BlockedUser::block( $this->blocker, $this->blocked );

		$this->assertTrue( BlockedUser::unblock( $this->blocker, $this->blocked ) );
		$this->assertTrue( BlockedUser::unblock( $this->blocker, $this->blocked ), 'Unblocking someone not blocked is still a success.' );

		$this->assertSame( 0, $this->rows() );
		$this->assertSame( 1, $this->unblocks );
	}
}
