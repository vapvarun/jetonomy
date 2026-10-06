<?php
/**
 * Pending join requests are settled when a space stops taking requests, and a
 * repeat decision on the same requester no longer fails on the unique key.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

class JoinRequestResolveTest extends WP_UnitTestCase {

	private int $category_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->category_id = (int) Category::create( array( 'name' => 'JR', 'slug' => 'jr-' . uniqid() ) );
	}

	private function space( string $visibility, string $join_policy ): int {
		return (int) Space::create(
			array(
				'title'       => 'JR Space',
				'slug'        => 'jr-space-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => $visibility,
				'join_policy' => $join_policy,
			)
		);
	}

	private function status_of( int $space_id, int $user_id ): array {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}jt_join_requests WHERE space_id = %d AND user_id = %d ORDER BY id",
				$space_id,
				$user_id
			)
		);
	}

	public function test_join_mode_matches_the_join_gate(): void {
		$this->assertSame( 'open', Space::join_mode( 'public', 'open' ) );
		$this->assertSame( 'request', Space::join_mode( 'public', 'approval' ) );
		$this->assertSame( 'request', Space::join_mode( 'private', 'open' ) );
		$this->assertSame( 'invite', Space::join_mode( 'public', 'invite' ) );
		$this->assertSame( 'invite', Space::join_mode( 'hidden', 'invite' ) );
	}

	public function test_approval_to_open_admits_and_approves_pending(): void {
		$space = $this->space( 'public', 'approval' );
		$users = self::factory()->user->create_many( 2 );
		foreach ( $users as $uid ) {
			$this->assertSame( 'pending', SpaceMember::join( $space, $uid )['status'] );
		}
		$approved = did_action( 'jetonomy_join_request_approved' );

		Space::update( $space, array( 'join_policy' => 'open' ) );

		$this->assertSame( array(), JoinRequest::list_pending_for_space( $space ), 'queue must be empty' );
		foreach ( $users as $uid ) {
			$this->assertTrue( SpaceMember::is_member( $space, $uid ) );
			$this->assertSame( array( 'approved' ), $this->status_of( $space, $uid ) );
		}
		$this->assertSame( $approved + 2, did_action( 'jetonomy_join_request_approved' ), 'requesters are told' );
	}

	public function test_approval_to_invite_denies_pending(): void {
		$space = $this->space( 'public', 'approval' );
		$uid   = self::factory()->user->create();
		SpaceMember::join( $space, $uid );
		$denied = did_action( 'jetonomy_join_request_denied' );

		Space::update( $space, array( 'join_policy' => 'invite' ) );

		$this->assertSame( array( 'denied' ), $this->status_of( $space, $uid ) );
		$this->assertFalse( SpaceMember::is_member( $space, $uid ) );
		$this->assertSame( $denied + 1, did_action( 'jetonomy_join_request_denied' ) );
	}

	public function test_private_to_public_with_open_policy_settles_too(): void {
		$space = $this->space( 'private', 'open' );
		$uid   = self::factory()->user->create();
		$this->assertSame( 'pending', SpaceMember::join( $space, $uid )['status'] );

		Space::update( $space, array( 'visibility' => 'public' ) );

		$this->assertSame( array( 'approved' ), $this->status_of( $space, $uid ) );
		$this->assertTrue( SpaceMember::is_member( $space, $uid ) );
	}

	public function test_changes_that_still_take_requests_leave_the_queue_alone(): void {
		$space = $this->space( 'public', 'approval' );
		$uid   = self::factory()->user->create();
		SpaceMember::join( $space, $uid );

		Space::update( $space, array( 'visibility' => 'private' ) );
		Space::update( $space, array( 'title' => 'Renamed' ) );

		$this->assertSame( array( 'pending' ), $this->status_of( $space, $uid ) );
	}

	public function test_second_decision_on_a_re_request_does_not_stick_at_pending(): void {
		$space = $this->space( 'public', 'approval' );
		$uid   = self::factory()->user->create();

		SpaceMember::join( $space, $uid );
		$this->assertTrue( JoinRequest::deny( (int) JoinRequest::find_pending( $space, $uid )->id, 1 ) );
		SpaceMember::join( $space, $uid );
		$this->assertTrue( JoinRequest::deny( (int) JoinRequest::find_pending( $space, $uid )->id, 1 ), 'duplicate-key collision used to make this false' );

		$this->assertNull( JoinRequest::find_pending( $space, $uid ) );
		$this->assertSame( array( 'denied' ), $this->status_of( $space, $uid ) );

		// Same collision on the bulk path: an older denial plus a new request.
		SpaceMember::join( $space, $uid );
		Space::update( $space, array( 'join_policy' => 'invite' ) );
		$this->assertSame( array( 'denied' ), $this->status_of( $space, $uid ) );
	}
}
