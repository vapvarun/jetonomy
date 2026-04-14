<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\DB\Schema;

class CategoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_create_returns_id(): void {
		$id = Category::create( [ 'name' => 'Test', 'slug' => 'test-' . uniqid(), 'visibility' => 'public' ] );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_find_returns_object(): void {
		$id  = Category::create( [ 'name' => 'Dev', 'slug' => 'dev' ] );
		$cat = Category::find( $id );
		$this->assertIsObject( $cat );
		$this->assertEquals( 'Dev', $cat->name );
		$this->assertEquals( 'dev', $cat->slug );
	}

	public function test_find_by_slug(): void {
		Category::create( [ 'name' => 'Design', 'slug' => 'design' ] );
		$cat = Category::find_by_slug( 'design' );
		$this->assertNotNull( $cat );
		$this->assertEquals( 'Design', $cat->name );
	}

	public function test_find_by_slug_returns_null_for_missing(): void {
		$this->assertNull( Category::find_by_slug( 'nonexistent' ) );
	}

	public function test_find_returns_null_for_missing_id(): void {
		$this->assertNull( Category::find( 999999 ) );
	}

	public function test_list_top_level_excludes_children(): void {
		$parent = Category::create( [ 'name' => 'Parent', 'slug' => 'parent-' . uniqid() ] );
		Category::create( [ 'name' => 'Child', 'slug' => 'child-' . uniqid(), 'parent_id' => $parent ] );
		Category::create( [ 'name' => 'Other', 'slug' => 'other-' . uniqid() ] );

		$top   = Category::list_top_level();
		$names = array_map( fn( $c ) => $c->name, $top );
		$this->assertContains( 'Parent', $names );
		$this->assertContains( 'Other', $names );
		$this->assertNotContains( 'Child', $names );
	}

	public function test_list_top_level_sorts_by_sort_order(): void {
		// Use unique names so this test is robust against rows leaked from other
		// tests (custom jt_* tables aren't covered by WP_UnitTestCase transactions).
		$suffix = uniqid();
		Category::create( [ 'name' => "B-$suffix", 'slug' => "b-$suffix", 'sort_order' => 2 ] );
		Category::create( [ 'name' => "A-$suffix", 'slug' => "a-$suffix", 'sort_order' => 1 ] );
		$top   = Category::list_top_level();
		$names = array_map( fn( $c ) => $c->name, $top );
		$idx_a = array_search( "A-$suffix", $names, true );
		$idx_b = array_search( "B-$suffix", $names, true );
		$this->assertNotFalse( $idx_a, 'A category not found in top level' );
		$this->assertNotFalse( $idx_b, 'B category not found in top level' );
		$this->assertLessThan( $idx_b, $idx_a, 'A (sort_order=1) should come before B (sort_order=2)' );
	}

	public function test_list_children_returns_correct_children(): void {
		$parent = Category::create( [ 'name' => 'Root', 'slug' => 'root-' . uniqid() ] );
		Category::create( [ 'name' => 'Child1', 'slug' => 'child1-' . uniqid(), 'parent_id' => $parent ] );
		Category::create( [ 'name' => 'Child2', 'slug' => 'child2-' . uniqid(), 'parent_id' => $parent ] );
		Category::create( [ 'name' => 'Other', 'slug' => 'other2-' . uniqid() ] );

		$children = Category::list_children( $parent );
		$this->assertCount( 2, $children );
		$names = array_map( fn( $c ) => $c->name, $children );
		$this->assertContains( 'Child1', $names );
		$this->assertContains( 'Child2', $names );
	}

	public function test_increment_space_count(): void {
		$id = Category::create( [ 'name' => 'T', 'slug' => 't-' . uniqid() ] );
		Category::increment_space_count( $id );
		Category::increment_space_count( $id );
		$cat = Category::find( $id );
		$this->assertEquals( 2, (int) $cat->space_count );
	}

	public function test_increment_space_count_decrement(): void {
		$id = Category::create( [ 'name' => 'Dec', 'slug' => 'dec-' . uniqid() ] );
		Category::increment_space_count( $id, 3 );
		Category::increment_space_count( $id, -1 );
		$cat = Category::find( $id );
		$this->assertEquals( 2, (int) $cat->space_count );
	}

	public function test_update(): void {
		$id = Category::create( [ 'name' => 'Old', 'slug' => 'old-' . uniqid() ] );
		Category::update( $id, [ 'name' => 'New' ] );
		$cat = Category::find( $id );
		$this->assertEquals( 'New', $cat->name );
	}

	public function test_delete(): void {
		$id = Category::create( [ 'name' => 'Del', 'slug' => 'del-' . uniqid() ] );
		Category::delete( $id );
		$this->assertNull( Category::find( $id ) );
	}
}
