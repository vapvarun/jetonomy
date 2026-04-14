<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Member_Journey;
use Jetonomy\Models\Category;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\DB\Schema;

/**
 * Exercises every Member_Journey method against the real model layer.
 *
 * Each test provisions a fresh category + one open-policy space + one
 * approval-policy space + a subscriber user under a unique slug suffix so
 * parallel runs inside the same WP test DB don't collide. Journey methods
 * are pure PHP with no WP-CLI coupling, so these tests run through the
 * standard WP_UnitTestCase bootstrap.
 */
class MemberJourneyTest extends WP_UnitTestCase {

	private Member_Journey $journey;

	private int $open_space_id;

	private int $approval_space_id;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new Member_Journey();

		$suffix = uniqid( 'mj_', true );
		$cat_id = (int) Category::create(
			[
				'name' => 'MJ Test Category',
				'slug' => 'mj-cat-' . $suffix,
			]
		);

		$this->open_space_id = (int) Space::create(
			[
				'category_id' => $cat_id,
				'title'       => 'MJ Open Space',
				'slug'        => 'mj-open-' . $suffix,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);

		$this->approval_space_id = (int) Space::create(
			[
				'category_id' => $cat_id,
				'title'       => 'MJ Approval Space',
				'slug'        => 'mj-approval-' . $suffix,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'approval',
			]
		);

		$this->user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function test_join_adds_member_with_default_role(): void {
		$result = $this->journey->join( $this->open_space_id, $this->user_id );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( 'member', $result->data['role'] );
		$this->assertTrue( SpaceMember::is_member( $this->open_space_id, $this->user_id ) );
		$this->assertSame( 'member', SpaceMember::get_role( $this->open_space_id, $this->user_id ) );
	}

	public function test_join_with_explicit_role(): void {
		$result = $this->journey->join( $this->open_space_id, $this->user_id, 'moderator' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'moderator', $result->data['role'] );
		$this->assertSame( 'moderator', SpaceMember::get_role( $this->open_space_id, $this->user_id ) );
	}

	public function test_leave_removes_member(): void {
		$this->journey->join( $this->open_space_id, $this->user_id );
		$this->assertTrue( SpaceMember::is_member( $this->open_space_id, $this->user_id ) );

		$result = $this->journey->leave( $this->open_space_id, $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( SpaceMember::is_member( $this->open_space_id, $this->user_id ) );
	}

	public function test_set_role_rejects_invalid_role(): void {
		$result = $this->journey->set_role( $this->open_space_id, $this->user_id, 'owner' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'role must be one of', $result->first_error() );
	}

	public function test_set_role_upserts(): void {
		$this->journey->join( $this->open_space_id, $this->user_id, 'member' );

		$result = $this->journey->set_role( $this->open_space_id, $this->user_id, 'admin' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'admin', $result->data['role'] );
		$this->assertSame( 'admin', SpaceMember::get_role( $this->open_space_id, $this->user_id ) );
	}

	public function test_is_member_returns_boolean(): void {
		$before = $this->journey->is_member( $this->open_space_id, $this->user_id );
		$this->assertTrue( $before->is_success() );
		$this->assertFalse( $before->data['is_member'] );

		$this->journey->join( $this->open_space_id, $this->user_id );

		$after = $this->journey->is_member( $this->open_space_id, $this->user_id );
		$this->assertTrue( $after->is_success() );
		$this->assertTrue( $after->data['is_member'] );
	}

	public function test_get_role_returns_current_role(): void {
		$this->journey->join( $this->open_space_id, $this->user_id, 'moderator' );

		$result = $this->journey->get_role( $this->open_space_id, $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'moderator', $result->data['role'] );
	}

	public function test_list_space_members_returns_items_and_columns(): void {
		$this->journey->join( $this->open_space_id, $this->user_id );

		$result = $this->journey->list_space_members( $this->open_space_id );

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertNotEmpty( $result->data['items'] );
		$this->assertSame( [ 'space_id', 'user_id', 'role', 'joined_at' ], $result->data['columns'] );

		$user_ids = array_column( $result->data['items'], 'user_id' );
		$this->assertContains( $this->user_id, $user_ids );
	}

	public function test_submit_join_request_creates_row(): void {
		$result = $this->journey->submit_join_request( $this->approval_space_id, $this->user_id, 'Please' );

		$this->assertTrue( $result->is_success() );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( 'pending', $result->data['status'] );

		$pending = JoinRequest::find_pending( $this->approval_space_id, $this->user_id );
		$this->assertNotNull( $pending );
		$this->assertSame( 'Please', $pending->message );
	}

	public function test_approve_join_request_adds_member(): void {
		$submit     = $this->journey->submit_join_request( $this->approval_space_id, $this->user_id );
		$request_id = (int) $submit->data['id'];
		$reviewer   = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$result = $this->journey->approve_join_request( $request_id, $reviewer );

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( 'approved', $result->data['status'] );
		$this->assertSame( 'member', $result->data['role'] );
		$this->assertTrue( SpaceMember::is_member( $this->approval_space_id, $this->user_id ) );
	}

	public function test_deny_join_request_sets_denied_status(): void {
		$submit     = $this->journey->submit_join_request( $this->approval_space_id, $this->user_id );
		$request_id = (int) $submit->data['id'];
		$reviewer   = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$result = $this->journey->deny_join_request( $request_id, $reviewer );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'denied', $result->data['status'] );
		$this->assertFalse( SpaceMember::is_member( $this->approval_space_id, $this->user_id ) );
	}

	public function test_list_pending_requests_returns_pending_only(): void {
		$this->journey->submit_join_request( $this->approval_space_id, $this->user_id, 'First' );

		$other_user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$submit     = $this->journey->submit_join_request( $this->approval_space_id, $other_user, 'Second' );
		$reviewer   = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Deny the second request — it should no longer appear in the pending list.
		$this->journey->deny_join_request( (int) $submit->data['id'], $reviewer );

		$result = $this->journey->list_pending_requests( $this->approval_space_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 'id', 'space_id', 'user_id', 'status', 'message', 'created_at' ], $result->data['columns'] );
		$this->assertCount( 1, $result->data['items'] );
		$this->assertSame( $this->user_id, $result->data['items'][0]['user_id'] );
		$this->assertSame( 'pending', $result->data['items'][0]['status'] );
	}
}
