<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * Exercises every Space_Journey method against the real model layer.
 *
 * Each test provisions a dedicated category under a unique slug suffix so
 * parallel runs inside the same WP test DB don't collide on UNIQUE slug
 * constraints. Journey methods are pure PHP with no WP-CLI coupling, so
 * these tests run through the standard WP_UnitTestCase bootstrap.
 */
class SpaceJourneyTest extends WP_UnitTestCase {

	private Space_Journey $journey;

	private int $category_id;

	private string $suffix;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new Space_Journey();
		$this->suffix  = uniqid( 'sj_', true );

		$this->category_id = (int) Category::create(
			[
				'name' => 'SJ Test Category',
				'slug' => 'sj-' . $this->suffix,
			]
		);
	}

	public function test_create_succeeds_with_valid_input(): void {
		$result = $this->journey->create(
			[
				'title'       => 'General',
				'slug'        => 'general-' . $this->suffix,
				'category_id' => $this->category_id,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertIsInt( $result->data['id'] );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( $this->category_id, $result->data['category_id'] );
		$this->assertSame( 'forum', $result->data['type'] );
		$this->assertSame( 'public', $result->data['visibility'] );
		$this->assertSame( 'open', $result->data['join_policy'] );
	}

	public function test_create_fails_when_required_fields_missing(): void {
		$result = $this->journey->create(
			[
				'title' => 'General',
				// slug + category_id intentionally omitted.
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Missing required fields', $result->first_error() );
		$this->assertStringContainsString( 'slug', $result->first_error() );
		$this->assertStringContainsString( 'category_id', $result->first_error() );
	}

	public function test_create_rejects_invalid_join_policy(): void {
		$result = $this->journey->create(
			[
				'title'       => 'General',
				'slug'        => 'general-' . $this->suffix,
				'category_id' => $this->category_id,
				'join_policy' => 'not-a-policy',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'join_policy', $result->first_error() );
	}

	public function test_create_rejects_invalid_visibility(): void {
		$result = $this->journey->create(
			[
				'title'       => 'General',
				'slug'        => 'general-' . $this->suffix,
				'category_id' => $this->category_id,
				'visibility'  => 'semi-public',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'visibility', $result->first_error() );
	}

	public function test_update_only_applies_whitelisted_fields(): void {
		$space_id = $this->make_space();

		$result = $this->journey->update(
			$space_id,
			[
				'title'       => 'New title',
				'category_id' => 99999, // should be silently dropped (not in whitelist).
			]
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 'title' ], $result->data['updated'] );

		$space = Space::find( $space_id );
		$this->assertSame( 'New title', $space->title );
		$this->assertSame( $this->category_id, (int) $space->category_id );
	}

	public function test_delete_removes_row(): void {
		$space_id = $this->make_space();

		$result = $this->journey->delete( $space_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $space_id, $result->data['id'] );
		$this->assertNull( Space::find( $space_id ) );
	}

	public function test_get_returns_row(): void {
		$space_id = $this->make_space();

		$result = $this->journey->get( $space_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $space_id, (int) $result->data['id'] );
		$this->assertArrayHasKey( 'title', $result->data );
	}

	public function test_get_by_slug_returns_row(): void {
		$slug     = 'lookup-' . $this->suffix;
		$space_id = (int) Space::create(
			[
				'category_id' => $this->category_id,
				'title'       => 'Lookup Space',
				'slug'        => $slug,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);

		$result = $this->journey->get_by_slug( $slug );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $space_id, (int) $result->data['id'] );
		$this->assertSame( $slug, $result->data['slug'] );
	}

	public function test_set_join_policy_persists(): void {
		$space_id = $this->make_space();

		$result = $this->journey->set_join_policy( $space_id, 'approval' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'approval', $result->data['join_policy'] );

		$space = Space::find( $space_id );
		$this->assertSame( 'approval', $space->join_policy );
	}

	public function test_set_visibility_persists(): void {
		$space_id = $this->make_space();

		$result = $this->journey->set_visibility( $space_id, 'private' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'private', $result->data['visibility'] );

		$space = Space::find( $space_id );
		$this->assertSame( 'private', $space->visibility );
	}

	public function test_set_settings_merges_not_replaces(): void {
		$space_id = $this->make_space();

		// Seed with initial settings.
		$first = $this->journey->set_settings(
			$space_id,
			[
				'posts_per_page' => 10,
				'pinned_tag'     => 'welcome',
			]
		);
		$this->assertTrue( $first->is_success() );

		// Merge new key, keep existing ones.
		$second = $this->journey->set_settings( $space_id, [ 'sort' => 'latest' ] );
		$this->assertTrue( $second->is_success() );

		$merged = Space::get_settings( $space_id );
		$this->assertSame( 10, $merged['posts_per_page'] );
		$this->assertSame( 'welcome', $merged['pinned_tag'] );
		$this->assertSame( 'latest', $merged['sort'] );
	}

	public function test_list_by_category_returns_items_and_columns(): void {
		$this->make_space( 'alpha' );
		$this->make_space( 'beta' );

		$result = $this->journey->list_by_category( $this->category_id );

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertGreaterThanOrEqual( 2, count( $result->data['items'] ) );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'title', $result->data['columns'] );
		$this->assertContains( 'join_policy', $result->data['columns'] );
	}

	/**
	 * Create a space fixture with a unique slug.
	 *
	 * @param string $prefix Slug prefix so callers can create multiple per test.
	 */
	private function make_space( string $prefix = 'fix' ): int {
		return (int) Space::create(
			[
				'category_id' => $this->category_id,
				'title'       => 'Fixture ' . $prefix . ' ' . $this->suffix,
				'slug'        => $prefix . '-' . uniqid( '', true ),
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
	}
}
