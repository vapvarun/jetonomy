<?php
namespace Jetonomy\Tests\Integration\Permissions;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Cache;
use Jetonomy\DB\Schema;

class FullPermissionFlowTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * Flush all Jetonomy object-cache entries so permission checks
	 * re-evaluate against the current DB state.
	 *
	 * Required in tests because Permission_Engine::can() caches results
	 * for 60 seconds, which spans the entire test method execution.
	 */
	private function flush_permission_cache(): void {
		Cache::flush();
		// Fall back to full WP object cache flush for the jetonomy group
		// if flush_group is not available.
		wp_cache_flush();
	}

	/**
	 * Helper: grant a user the standard Jetonomy subscriber WP capabilities.
	 */
	private function grant_jetonomy_caps( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		$user->add_cap( 'jetonomy_read' );
		$user->add_cap( 'jetonomy_create_posts' );
		$user->add_cap( 'jetonomy_create_replies' );
		$user->add_cap( 'jetonomy_vote' );
		$user->add_cap( 'jetonomy_flag' );
		$user->add_cap( 'jetonomy_close_posts' );
		$user->add_cap( 'jetonomy_edit_others_posts' );
		$user->add_cap( 'jetonomy_pin_posts' );
	}

	public function test_complete_private_space_flow(): void {
		// 1. Create a private space.
		$cat_id   = Category::create( [ 'name' => 'Flow Cat', 'slug' => 'flow-cat' ] );
		$space_id = Space::create( [
			'title'       => 'Private Forum',
			'slug'        => 'private-forum-flow',
			'category_id' => $cat_id,
			'visibility'  => 'private',
		] );

		// 2. Create a user and grant WP caps.
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_jetonomy_caps( $user_id );

		// 3. Verify access is denied before membership.
		$this->assertFalse(
			Permission_Engine::can( $user_id, 'read', $space_id ),
			'Non-member should not read a private space.'
		);

		// 4. Add user as a member.
		SpaceMember::add( $space_id, $user_id, 'member' );
		$this->flush_permission_cache();

		// 5. Verify access is now allowed.
		$this->assertTrue(
			Permission_Engine::can( $user_id, 'read', $space_id ),
			'Member should be able to read a private space.'
		);
		$this->assertTrue(
			Permission_Engine::can( $user_id, 'create_posts', $space_id ),
			'Member should be able to create posts in a private space.'
		);

		// 6. Ban the user.
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		Restriction::ban( $user_id, 'global_ban', $admin_id );
		$this->flush_permission_cache();

		// 7. Verify the banned user is denied even as a member.
		$this->assertFalse(
			Permission_Engine::can( $user_id, 'read', $space_id ),
			'Banned member should be denied access.'
		);
	}

	public function test_public_space_visibility_rules(): void {
		$cat_id   = Category::create( [ 'name' => 'Public Cat', 'slug' => 'public-cat-flow' ] );
		$space_id = Space::create( [
			'title'       => 'Public Forum',
			'slug'        => 'public-forum-flow',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_jetonomy_caps( $user_id );

		// Non-member of a public + open space can read and participate.
		$this->assertTrue( Permission_Engine::can( $user_id, 'read', $space_id ) );
		$this->assertTrue( Permission_Engine::can( $user_id, 'create_posts', $space_id ) );

		// Non-member cannot perform moderation actions even in a public space.
		$this->assertFalse( Permission_Engine::can( $user_id, 'edit_others_posts', $space_id ) );

		// After joining as member, participation is still allowed.
		SpaceMember::add( $space_id, $user_id, 'member' );
		$this->flush_permission_cache();
		$this->assertTrue( Permission_Engine::can( $user_id, 'create_posts', $space_id ) );
	}

	public function test_moderator_role_grants_moderation_actions(): void {
		$cat_id   = Category::create( [ 'name' => 'Mod Cat', 'slug' => 'mod-cat-flow' ] );
		$space_id = Space::create( [
			'title'       => 'Mod Space',
			'slug'        => 'mod-space-flow',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_jetonomy_caps( $user_id );

		// Assign moderator role.
		SpaceMember::add( $space_id, $user_id, 'moderator' );

		// Moderators bypass trust level requirements for moderation actions.
		$this->assertTrue( Permission_Engine::can( $user_id, 'close_posts', $space_id ) );
		$this->assertTrue( Permission_Engine::can( $user_id, 'pin_posts', $space_id ) );
		$this->assertTrue( Permission_Engine::can( $user_id, 'edit_others_posts', $space_id ) );
	}

	public function test_wp_admin_has_full_access_everywhere(): void {
		$cat_id   = Category::create( [ 'name' => 'Admin Cat', 'slug' => 'admin-cat-flow' ] );
		$space_id = Space::create( [
			'title'       => 'Admin Space',
			'slug'        => 'admin-space-flow',
			'category_id' => $cat_id,
			'visibility'  => 'private',
		] );

		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );

		// Admin bypasses all layers — no SpaceMember entry needed.
		$this->assertTrue( Permission_Engine::can( $admin_id, 'read', $space_id ) );
		$this->assertTrue( Permission_Engine::can( $admin_id, 'manage_spaces', $space_id ) );
		$this->assertTrue( Permission_Engine::can( $admin_id, 'delete_others_posts', $space_id ) );
	}

	public function test_trust_level_gates_apply_to_members(): void {
		$cat_id   = Category::create( [ 'name' => 'Trust Cat', 'slug' => 'trust-cat-flow' ] );
		$space_id = Space::create( [
			'title'       => 'Trust Space',
			'slug'        => 'trust-space-flow',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_jetonomy_caps( $user_id );
		SpaceMember::add( $space_id, $user_id, 'member' );

		// Trust level 0 cannot edit others' posts.
		UserProfile::find_or_create( $user_id );
		$this->assertFalse( Permission_Engine::can( $user_id, 'edit_others_posts', $space_id ) );

		// After trust level is elevated to 3, they can.
		UserProfile::update_profile( $user_id, [ 'trust_level' => 3 ] );
		$this->flush_permission_cache();
		$this->assertTrue( Permission_Engine::can( $user_id, 'edit_others_posts', $space_id ) );
	}

	public function test_hidden_space_requires_membership(): void {
		$cat_id   = Category::create( [ 'name' => 'Hidden Cat', 'slug' => 'hidden-cat-flow' ] );
		$space_id = Space::create( [
			'title'       => 'Hidden Space',
			'slug'        => 'hidden-space-flow',
			'category_id' => $cat_id,
			'visibility'  => 'hidden',
		] );

		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_jetonomy_caps( $user_id );

		// Hidden space — non-member denied.
		$this->assertFalse( Permission_Engine::can( $user_id, 'read', $space_id ) );

		// After membership, allowed.
		SpaceMember::add( $space_id, $user_id, 'member' );
		$this->flush_permission_cache();
		$this->assertTrue( Permission_Engine::can( $user_id, 'read', $space_id ) );
	}

	public function test_ban_check_is_layer_zero_before_wp_admin_bypass(): void {
		// A WP admin who is globally banned should still be denied.
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$issuer   = $this->factory()->user->create( [ 'role' => 'administrator' ] );

		Restriction::ban( $admin_id, 'global_ban', $issuer );

		$cat_id   = Category::create( [ 'name' => 'BanAdmin Cat', 'slug' => 'banadmin-cat' ] );
		$space_id = Space::create( [
			'title'       => 'BanAdmin Space',
			'slug'        => 'banadmin-space',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$this->assertFalse( Permission_Engine::can( $admin_id, 'read', $space_id ) );
	}

	public function test_space_member_count_increments_on_add(): void {
		$cat_id   = Category::create( [ 'name' => 'Member Count Cat', 'slug' => 'member-count-cat' ] );
		$space_id = Space::create( [
			'title'       => 'Member Count Space',
			'slug'        => 'member-count-space',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$user1 = $this->factory()->user->create();
		$user2 = $this->factory()->user->create();

		SpaceMember::add( $space_id, $user1 );
		SpaceMember::add( $space_id, $user2 );

		$space = Space::find( $space_id );
		$this->assertEquals( 2, (int) $space->member_count );
	}

	public function test_space_member_count_decrements_on_remove(): void {
		$cat_id   = Category::create( [ 'name' => 'Remove Member Cat', 'slug' => 'remove-member-cat' ] );
		$space_id = Space::create( [
			'title'       => 'Remove Member Space',
			'slug'        => 'remove-member-space',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$user_id = $this->factory()->user->create();
		SpaceMember::add( $space_id, $user_id );

		$space_after_add = Space::find( $space_id );
		$this->assertEquals( 1, (int) $space_after_add->member_count );

		SpaceMember::remove( $space_id, $user_id );

		$space_after_remove = Space::find( $space_id );
		$this->assertEquals( 0, (int) $space_after_remove->member_count );
	}
}
