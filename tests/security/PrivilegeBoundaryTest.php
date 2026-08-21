<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * Two privilege boundaries that were enforced at some callers but not others.
 *
 * Both bugs have the same shape, and it is the shape worth guarding against:
 * a rule was written into ONE entry point, the other entry points kept their
 * own copy of the logic without it, and the surface everybody actually used
 * turned out to be one of the others.
 *
 * - Restriction: REST_Moderation_Controller refused to let a moderator
 *   restrict an administrator. The admin-ajax handler the Moderation screen
 *   posts to did not, and neither did the model, so a default Editor (who
 *   holds jetonomy_moderate out of the box) could global_ban user 1 and lock
 *   the owner out of their own site (Basecamp 10227908469).
 * - SpaceMember: the REST join route enforced join_policy. The WP Abilities
 *   `jetonomy/join-space` ability branched only on `visibility === 'private'`
 *   and called add() for everything else, so a subscriber could join a HIDDEN
 *   or INVITE-ONLY space and read every post in it (Basecamp 10227908583).
 *
 * These tests drive the MODEL, deliberately. Testing through one controller
 * would prove only what that controller does, which is exactly the mistake
 * that produced both bugs.
 */
class PrivilegeBoundaryTest extends WP_UnitTestCase {

	private int $category_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->category_id = (int) Category::create(
			[
				'name' => 'Boundary',
				'slug' => 'boundary-' . uniqid(),
			]
		);
	}

	private function make_space( string $visibility, string $join_policy ): int {
		return (int) Space::create(
			[
				'title'       => 'Boundary space',
				'slug'        => 'boundary-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => $visibility,
				'join_policy' => $join_policy,
				'author_id'   => 1,
			]
		);
	}

	// ---------------------------------------------------------- restrictions --

	public function test_a_moderator_cannot_restrict_an_administrator(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertTrue(
			user_can( $editor, 'jetonomy_moderate' ),
			'precondition: the editor role carries jetonomy_moderate by default'
		);

		$allowed = Restriction::actor_may_restrict( $admin, $editor );
		$this->assertWPError( $allowed );
		$this->assertSame( 'jetonomy_cannot_ban_admin', $allowed->get_error_code() );

		$this->assertSame(
			0,
			Restriction::ban( $admin, 'global_ban', $editor ),
			'the model itself must refuse, not just the controllers above it'
		);
	}

	public function test_a_moderator_cannot_restrict_a_peer_moderator(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$peer   = self::factory()->user->create( [ 'role' => 'editor' ] );

		$allowed = Restriction::actor_may_restrict( $peer, $editor );
		$this->assertWPError( $allowed );
		$this->assertSame( 'jetonomy_cannot_ban_peer_moderator', $allowed->get_error_code() );
	}

	public function test_nobody_restricts_themselves(): void {
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$allowed = Restriction::actor_may_restrict( $editor, $editor );

		$this->assertWPError( $allowed );
		$this->assertSame( 'jetonomy_cannot_ban_self', $allowed->get_error_code() );
	}

	public function test_a_moderator_can_still_restrict_an_ordinary_member(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( Restriction::actor_may_restrict( $member, $editor ) );
		$this->assertGreaterThan(
			0,
			Restriction::ban( $member, 'global_ban', $editor ),
			'the guard must not cost moderators their actual job'
		);
	}

	public function test_an_administrator_may_restrict_a_peer_and_the_system_is_unrestricted(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Owner-level action: allowed. The rule is about escalation, not an
		// absolute "administrators are unbannable".
		$this->assertTrue( Restriction::actor_may_restrict( $other, $admin ) );

		// System-issued (no actor) is not second-guessed - automated moderation
		// has no user to measure privilege against.
		$this->assertTrue( Restriction::actor_may_restrict( $other, 0 ) );
	}

	// ----------------------------------------------------------------- joins --

	public function test_a_hidden_space_cannot_be_self_joined(): void {
		$space = $this->make_space( 'hidden', 'invite' );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = SpaceMember::join( $space, $user );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_invite_only', $result->get_error_code() );
		$this->assertFalse(
			SpaceMember::is_member( $space, $user ),
			'a refused join must not leave a roster row behind'
		);
	}

	public function test_an_invite_only_space_cannot_be_self_joined(): void {
		$space = $this->make_space( 'public', 'invite' );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = SpaceMember::join( $space, $user );

		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_invite_only', $result->get_error_code() );
		$this->assertFalse( SpaceMember::is_member( $space, $user ) );
	}

	public function test_an_approval_space_creates_a_request_not_a_membership(): void {
		$space = $this->make_space( 'public', 'approval' );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = SpaceMember::join( $space, $user );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertFalse(
			SpaceMember::is_member( $space, $user ),
			'approval must not admit anyone before somebody approves'
		);
	}

	public function test_an_open_space_still_admits_immediately(): void {
		$space = $this->make_space( 'public', 'open' );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = SpaceMember::join( $space, $user );

		$this->assertIsArray( $result );
		$this->assertSame( 'joined', $result['status'] );
		$this->assertTrue( SpaceMember::is_member( $space, $user ) );
	}

	public function test_a_repeat_approval_request_does_not_duplicate(): void {
		$space = $this->make_space( 'public', 'approval' );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		SpaceMember::join( $space, $user );
		$again = SpaceMember::join( $space, $user );

		$this->assertIsArray( $again );
		$this->assertSame( 'pending', $again['status'] );
		$this->assertTrue( $again['already_pending'] );
	}
}
