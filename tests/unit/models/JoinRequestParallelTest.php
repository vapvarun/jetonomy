<?php
/**
 * Two parallel join requests from one member announce the request once.
 *
 * The unique key (space, user, status) lets only one insert through, but SpaceMember::join() ignored the loser's
 * failed insert and fired jetonomy_join_request_created anyway: 20 parallel requests meant 12 hooks and 24 admin
 * notifications for one row.
 *
 * @package Jetonomy\Tests
 */

namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

class JoinRequestParallelTest extends WP_UnitTestCase {

	private int $space_id;
	private int $user_id;
	private int $announced = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$cat_id          = Category::create( array( 'name' => 'General', 'slug' => 'jr-' . uniqid() ) );
		$this->space_id  = Space::create( array( 'title' => 'Approval', 'slug' => 'jr-space-' . uniqid(), 'category_id' => $cat_id, 'visibility' => 'public', 'join_policy' => 'approval' ) );
		$this->user_id   = $this->factory()->user->create();
		$this->announced = 0;
		add_action( 'jetonomy_join_request_created', function (): void {
			++$this->announced;
		} );
	}

	private function rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_join_requests WHERE space_id = %d AND user_id = %d", $this->space_id, $this->user_id ) );
	}

	public function test_a_parallel_request_is_pending_and_announces_nothing(): void {
		global $wpdb;
		$done = false;
		add_filter(
			'query',
			function ( $sql ) use ( &$done, $wpdb ) {
				// The other request's insert lands just before ours.
				if ( ! $done && 0 === stripos( ltrim( $sql ), 'INSERT INTO' ) && false !== strpos( $sql, $wpdb->prefix . 'jt_join_requests' ) ) {
					$done = true;
					$wpdb->insert( $wpdb->prefix . 'jt_join_requests', array( 'space_id' => $this->space_id, 'user_id' => $this->user_id, 'message' => '', 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				}
				return $sql;
			}
		);

		$result = SpaceMember::join( $this->space_id, $this->user_id );

		$this->assertSame( 'pending', $result['status'] );
		$this->assertTrue( $result['already_pending'], 'It lost the race, so it answers as the pending request does.' );
		$this->assertSame( 1, $this->rows() );
		$this->assertSame( 0, $this->announced, 'The winner announced the request; the loser does not.' );
	}

	/** @covers-existing Pins behaviour that must not change; the fix is proved by the sibling tests. */
	public function test_a_single_request_is_announced_once_and_a_repeat_is_already_pending(): void {
		$first = SpaceMember::join( $this->space_id, $this->user_id );
		$again = SpaceMember::join( $this->space_id, $this->user_id );

		$this->assertFalse( $first['already_pending'] );
		$this->assertTrue( $again['already_pending'] );
		$this->assertSame( 1, $this->announced );
		$this->assertSame( 1, $this->rows() );
	}
}
