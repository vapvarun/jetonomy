<?php
namespace Jetonomy\Tests\Unit\Permissions;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Permissions\Rate_Limiter;
use Jetonomy\DB\Schema;

class PermissionEngineTest extends WP_UnitTestCase {

	private int $public_space_id;
	private int $private_space_id;
	private int $regular_user_id;
	private int $admin_user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id = Category::create( [ 'name' => 'Perm Cat', 'slug' => 'perm-cat' ] );

		$this->public_space_id = Space::create( [
			'title'       => 'Public Space',
			'slug'        => 'public-space-perm',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$this->private_space_id = Space::create( [
			'title'       => 'Private Space',
			'slug'        => 'private-space-perm',
			'category_id' => $cat_id,
			'visibility'  => 'private',
		] );

		$this->regular_user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_user_id   = $this->factory()->user->create( [ 'role' => 'administrator' ] );

		// Grant the WP capability required for Layer 1.
		$regular_user = get_user_by( 'id', $this->regular_user_id );
		$regular_user->add_cap( 'jetonomy_read' );
		$regular_user->add_cap( 'jetonomy_create_posts' );
		$regular_user->add_cap( 'jetonomy_create_replies' );
		$regular_user->add_cap( 'jetonomy_vote' );
		$regular_user->add_cap( 'jetonomy_flag' );
		$regular_user->add_cap( 'jetonomy_close_posts' );
		$regular_user->add_cap( 'jetonomy_edit_others_posts' );
		$regular_user->add_cap( 'jetonomy_pin_posts' );
	}

	public function test_public_space_read_allowed_for_any_user(): void {
		$can = Permission_Engine::can( $this->regular_user_id, 'read', $this->public_space_id );
		$this->assertTrue( $can );
	}

	public function test_private_space_read_denied_for_non_member(): void {
		$can = Permission_Engine::can( $this->regular_user_id, 'read', $this->private_space_id );
		$this->assertFalse( $can );
	}

	public function test_private_space_read_allowed_for_member(): void {
		SpaceMember::add( $this->private_space_id, $this->regular_user_id, 'member' );
		$can = Permission_Engine::can( $this->regular_user_id, 'read', $this->private_space_id );
		$this->assertTrue( $can );
	}

	public function test_wp_admin_bypasses_all_checks(): void {
		// Admin has no explicit SpaceMember entry, but should bypass everything.
		$can = Permission_Engine::can( $this->admin_user_id, 'manage_spaces', $this->private_space_id );
		$this->assertTrue( $can );
	}

	public function test_member_can_vote_in_public_space(): void {
		SpaceMember::add( $this->public_space_id, $this->regular_user_id, 'member' );
		$can = Permission_Engine::can( $this->regular_user_id, 'vote', $this->public_space_id );
		$this->assertTrue( $can );
	}

	public function test_moderator_can_close_posts(): void {
		SpaceMember::add( $this->public_space_id, $this->regular_user_id, 'moderator' );
		$can = Permission_Engine::can( $this->regular_user_id, 'close_posts', $this->public_space_id );
		$this->assertTrue( $can );
	}

	public function test_regular_member_cannot_close_posts_below_trust_level_3(): void {
		SpaceMember::add( $this->public_space_id, $this->regular_user_id, 'member' );

		// Ensure trust level is 0 (default).
		UserProfile::find_or_create( $this->regular_user_id );

		$can = Permission_Engine::can( $this->regular_user_id, 'close_posts', $this->public_space_id );
		$this->assertFalse( $can );
	}

	public function test_trust_level_3_member_can_edit_others_posts(): void {
		SpaceMember::add( $this->public_space_id, $this->regular_user_id, 'member' );
		UserProfile::find_or_create( $this->regular_user_id );
		UserProfile::update_profile( $this->regular_user_id, [ 'trust_level' => 3 ] );

		$can = Permission_Engine::can( $this->regular_user_id, 'edit_others_posts', $this->public_space_id );
		$this->assertTrue( $can );
	}

	public function test_banned_user_denied_everything(): void {
		$banned_user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$admin_id       = $this->factory()->user->create( [ 'role' => 'administrator' ] );

		Restriction::ban( $banned_user_id, 'global_ban', $admin_id );

		$can = Permission_Engine::can( $banned_user_id, 'read', $this->public_space_id );
		$this->assertFalse( $can );
	}

	public function test_banned_user_denied_even_with_admin_cap(): void {
		// A banned WP admin is still denied (ban check is Layer 0).
		Restriction::ban( $this->admin_user_id, 'global_ban', 1 );

		$can = Permission_Engine::can( $this->admin_user_id, 'read', $this->public_space_id );
		$this->assertFalse( $can );
	}

	public function test_rate_limiter_allows_below_threshold(): void {
		// Trust level 0 has a limit of 3 create_posts per day.
		$allowed = Rate_Limiter::check( $this->regular_user_id, 'create_posts', 0 );
		$this->assertTrue( $allowed );
	}

	public function test_rate_limiter_blocks_after_threshold(): void {
		$user_id = $this->factory()->user->create();

		// Exhaust the daily limit (3 for trust level 0).
		Rate_Limiter::increment( $user_id, 'create_posts' );
		Rate_Limiter::increment( $user_id, 'create_posts' );
		Rate_Limiter::increment( $user_id, 'create_posts' );

		$allowed = Rate_Limiter::check( $user_id, 'create_posts', 0 );
		$this->assertFalse( $allowed );
	}

	public function test_rate_limiter_no_limits_for_trust_level_1(): void {
		$user_id = $this->factory()->user->create();

		// Even if we set a high count, trust level 1+ has no limits.
		Rate_Limiter::increment( $user_id, 'create_posts' );
		Rate_Limiter::increment( $user_id, 'create_posts' );
		Rate_Limiter::increment( $user_id, 'create_posts' );
		Rate_Limiter::increment( $user_id, 'create_posts' );

		$allowed = Rate_Limiter::check( $user_id, 'create_posts', 1 );
		$this->assertTrue( $allowed );
	}

	public function test_non_member_of_public_space_can_only_read(): void {
		// Use a public space with approval-required join policy so non-members
		// cannot participate (open-policy spaces allow any logged-in user to post).
		$cat_id   = Category::create( [ 'name' => 'Approval Cat', 'slug' => 'approval-cat' ] );
		$space_id = Space::create( [
			'title'       => 'Approval Space',
			'slug'        => 'approval-space-perm',
			'category_id' => $cat_id,
			'visibility'  => 'public',
			'join_policy' => 'approval',
		] );

		// No SpaceMember record — non-member of public space with approval policy.
		$can_read   = Permission_Engine::can( $this->regular_user_id, 'read', $space_id );
		$can_create = Permission_Engine::can( $this->regular_user_id, 'create_posts', $space_id );

		$this->assertTrue( $can_read );
		$this->assertFalse( $can_create );
	}
}
