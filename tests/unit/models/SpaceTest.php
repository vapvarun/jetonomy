<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\DB\Schema;

class SpaceTest extends WP_UnitTestCase {

	private int $category_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->category_id = Category::create( [ 'name' => 'General', 'slug' => 'general-' . uniqid() ] );
	}

	private function make_space( array $overrides = [] ): int {
		return Space::create( array_merge(
			[
				'title'       => 'Test Space',
				'slug'        => 'test-space-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => 'public',
			],
			$overrides
		) );
	}

	public function test_create_returns_id(): void {
		$id = $this->make_space();
		$this->assertGreaterThan( 0, $id );
	}

	public function test_find_returns_object(): void {
		$id    = $this->make_space( [ 'title' => 'My Forum', 'slug' => 'my-forum' ] );
		$space = Space::find( $id );
		$this->assertIsObject( $space );
		$this->assertEquals( 'My Forum', $space->title );
		$this->assertEquals( 'my-forum', $space->slug );
	}

	public function test_find_by_slug(): void {
		$this->make_space( [ 'title' => 'Dev Talk', 'slug' => 'dev-talk' ] );
		$space = Space::find_by_slug( 'dev-talk' );
		$this->assertNotNull( $space );
		$this->assertEquals( 'Dev Talk', $space->title );
	}

	public function test_find_by_slug_returns_null_for_missing(): void {
		$this->assertNull( Space::find_by_slug( 'does-not-exist' ) );
	}

	public function test_list_by_category(): void {
		$cat2 = Category::create( [ 'name' => 'Other', 'slug' => 'other-cat' ] );

		$id1 = $this->make_space( [ 'slug' => 'space-a' ] );
		$id2 = $this->make_space( [ 'slug' => 'space-b' ] );
		Space::create( [
			'title'       => 'Different Cat',
			'slug'        => 'diff-cat',
			'category_id' => $cat2,
			'visibility'  => 'public',
		] );

		$spaces = Space::list_by_category( $this->category_id );
		$ids    = array_map( fn( $s ) => (int) $s->id, $spaces );
		$this->assertContains( $id1, $ids );
		$this->assertContains( $id2, $ids );

		// The space in the other category should not appear.
		foreach ( $spaces as $s ) {
			$this->assertEquals( $this->category_id, (int) $s->category_id );
		}
	}

	public function test_list_by_category_excludes_hidden_for_guest(): void {
		wp_set_current_user( 0 );

		$public_id = $this->make_space( [ 'slug' => 'public-vis' ] );
		$hidden_id = $this->make_space( [ 'slug' => 'hidden-vis', 'visibility' => 'hidden' ] );

		$ids = array_map( fn( $s ) => (int) $s->id, Space::list_by_category( $this->category_id ) );

		$this->assertContains( $public_id, $ids );
		$this->assertNotContains( $hidden_id, $ids, 'Guests must not see hidden spaces in the category listing.' );
	}

	public function test_list_by_category_excludes_hidden_for_non_member(): void {
		// Spaces seed their creator as admin, so we make them with creator_user_id=0
		// to keep the viewer cleanly outside the membership of the hidden space.
		$public_id = (int) Space::create(
			[
				'title'       => 'Public space NM',
				'slug'        => 'public-vis-nm-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => 'public',
			],
			0
		);
		$hidden_id = (int) Space::create(
			[
				'title'       => 'Hidden space NM',
				'slug'        => 'hidden-vis-nm-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => 'hidden',
			],
			0
		);

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$ids = array_map( fn( $s ) => (int) $s->id, Space::list_by_category( $this->category_id ) );

		$this->assertContains( $public_id, $ids );
		$this->assertNotContains( $hidden_id, $ids, 'Non-members must not see hidden spaces they do not belong to.' );
	}

	public function test_list_by_category_shows_hidden_to_member(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$hidden_id = $this->make_space( [ 'slug' => 'hidden-vis-member', 'visibility' => 'hidden' ] );
		SpaceMember::add( $hidden_id, $user_id, 'member' );

		$ids = array_map( fn( $s ) => (int) $s->id, Space::list_by_category( $this->category_id ) );

		$this->assertContains( $hidden_id, $ids, 'Members must see the hidden spaces they belong to.' );
	}

	public function test_list_by_category_shows_hidden_to_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$hidden_id = $this->make_space( [ 'slug' => 'hidden-vis-admin', 'visibility' => 'hidden' ] );

		$ids = array_map( fn( $s ) => (int) $s->id, Space::list_by_category( $this->category_id ) );

		$this->assertContains( $hidden_id, $ids, 'WP admins must see every hidden space, regardless of membership.' );
	}

	public function test_list_uncategorized_applies_visibility_predicate(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$guest_id = 0;

		$public_id = (int) Space::create(
			[
				'title'      => 'Uncat public',
				'slug'       => 'uncat-public-' . uniqid(),
				'visibility' => 'public',
			]
		);
		$hidden_id = (int) Space::create(
			[
				'title'      => 'Uncat hidden',
				'slug'       => 'uncat-hidden-' . uniqid(),
				'visibility' => 'hidden',
			]
		);

		wp_set_current_user( $guest_id );
		$guest_ids = array_map( fn( $s ) => (int) $s->id, Space::list_uncategorized() );
		$this->assertContains( $public_id, $guest_ids );
		$this->assertNotContains( $hidden_id, $guest_ids );

		wp_set_current_user( $admin_id );
		$admin_ids = array_map( fn( $s ) => (int) $s->id, Space::list_uncategorized() );
		$this->assertContains( $hidden_id, $admin_ids, 'Admins must see hidden uncategorized spaces too.' );
	}

	public function test_list_by_category_explicit_user_id_overrides_current_user(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$sub_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $sub_id );

		$hidden_id = $this->make_space( [ 'slug' => 'hidden-explicit', 'visibility' => 'hidden' ] );

		// Explicitly ask for the admin's view despite current user being a subscriber.
		$ids = array_map( fn( $s ) => (int) $s->id, Space::list_by_category( $this->category_id, $admin_id ) );
		$this->assertContains( $hidden_id, $ids );

		// Explicit 0 forces guest semantics.
		$guest_ids = array_map( fn( $s ) => (int) $s->id, Space::list_by_category( $this->category_id, 0 ) );
		$this->assertNotContains( $hidden_id, $guest_ids );
	}

	public function test_list_children(): void {
		$parent = $this->make_space( [ 'slug' => 'parent-space' ] );
		$child1 = Space::create( [
			'title'       => 'Child A',
			'slug'        => 'child-a',
			'category_id' => $this->category_id,
			'parent_id'   => $parent,
			'visibility'  => 'public',
		] );
		$child2 = Space::create( [
			'title'       => 'Child B',
			'slug'        => 'child-b',
			'category_id' => $this->category_id,
			'parent_id'   => $parent,
			'visibility'  => 'public',
		] );

		$children = Space::list_children( $parent );
		$this->assertCount( 2, $children );
		$ids = array_map( fn( $s ) => (int) $s->id, $children );
		$this->assertContains( $child1, $ids );
		$this->assertContains( $child2, $ids );
	}

	public function test_increment_post_count(): void {
		$id = $this->make_space();
		Space::increment_post_count( $id );
		Space::increment_post_count( $id );
		$space = Space::find( $id );
		$this->assertEquals( 2, (int) $space->post_count );
	}

	public function test_increment_post_count_decrement(): void {
		$id = $this->make_space();
		Space::increment_post_count( $id, 5 );
		Space::increment_post_count( $id, -2 );
		$space = Space::find( $id );
		$this->assertEquals( 3, (int) $space->post_count );
	}

	public function test_increment_member_count(): void {
		$id = $this->make_space();
		Space::increment_member_count( $id );
		Space::increment_member_count( $id );
		Space::increment_member_count( $id );
		$space = Space::find( $id );
		$this->assertEquals( 3, (int) $space->member_count );
	}

	public function test_get_settings_returns_decoded_array(): void {
		$id = Space::create( [
			'title'       => 'Settings Space',
			'slug'        => 'settings-space',
			'category_id' => $this->category_id,
			'settings'    => json_encode( [ 'allow_anonymous' => true, 'max_posts' => 100 ] ),
		] );

		$settings = Space::get_settings( $id );
		$this->assertIsArray( $settings );
		$this->assertTrue( $settings['allow_anonymous'] );
		$this->assertEquals( 100, $settings['max_posts'] );
	}

	public function test_get_settings_returns_empty_array_when_null(): void {
		$id       = $this->make_space();
		$settings = Space::get_settings( $id );
		$this->assertIsArray( $settings );
		$this->assertEmpty( $settings );
	}

	public function test_create_increments_category_space_count(): void {
		$cat_before = Category::find( $this->category_id );
		$count_before = (int) $cat_before->space_count;

		$this->make_space();
		$this->make_space();

		$cat_after = Category::find( $this->category_id );
		$this->assertEquals( $count_before + 2, (int) $cat_after->space_count );
	}

	public function test_update(): void {
		$id = $this->make_space( [ 'title' => 'Original Title', 'slug' => 'original-title' ] );
		Space::update( $id, [ 'title' => 'Updated Title' ] );
		$space = Space::find( $id );
		$this->assertEquals( 'Updated Title', $space->title );
	}

	public function test_delete(): void {
		$id = $this->make_space();
		Space::delete( $id );
		$this->assertNull( Space::find( $id ) );
	}

	public function test_validate_visibility_join_policy_rejects_hidden_open(): void {
		$result = Space::validate_visibility_join_policy( 'hidden', 'open' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'jetonomy_invalid_combo', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] ?? 0 );
	}

	public function test_validate_visibility_join_policy_rejects_hidden_approval(): void {
		$result = Space::validate_visibility_join_policy( 'hidden', 'approval' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'jetonomy_invalid_combo', $result->get_error_code() );
	}

	public function test_validate_visibility_join_policy_accepts_hidden_invite(): void {
		$this->assertTrue( Space::validate_visibility_join_policy( 'hidden', 'invite' ) );
	}

	public function test_validate_visibility_join_policy_accepts_public_and_private(): void {
		foreach ( [ 'public', 'private' ] as $vis ) {
			foreach ( [ 'open', 'approval', 'invite' ] as $jp ) {
				$this->assertTrue(
					Space::validate_visibility_join_policy( $vis, $jp ),
					"$vis + $jp should be allowed"
				);
			}
		}
	}
}
