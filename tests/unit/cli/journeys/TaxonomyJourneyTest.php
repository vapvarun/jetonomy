<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\Tag;
use Jetonomy\DB\Schema;

/**
 * Exercises every Taxonomy_Journey method against the real model layer.
 *
 * Each test provisions a dedicated category, space, and post under unique
 * slug suffixes so parallel runs inside the same WP test DB don't collide
 * on UNIQUE slug constraints. Journey methods are pure PHP with no WP-CLI
 * coupling, so these tests run through the standard WP_UnitTestCase
 * bootstrap without any CLI harness involvement.
 */
class TaxonomyJourneyTest extends WP_UnitTestCase {

	private Taxonomy_Journey $journey;

	private int $category_id;

	private int $space_id;

	private int $post_id;

	private int $author_id;

	private string $suffix;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new Taxonomy_Journey();
		$this->suffix  = uniqid( 'tj_', true );

		$this->category_id = (int) Category::create(
			[
				'name' => 'TJ Test Category',
				'slug' => 'tj-cat-' . $this->suffix,
			]
		);

		$this->space_id = (int) Space::create(
			[
				'category_id' => $this->category_id,
				'title'       => 'TJ Test Space',
				'slug'        => 'tj-space-' . $this->suffix,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);

		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$post_id       = Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'TJ Test Post',
				'content'   => 'Post body for taxonomy journey tests.',
				'slug'      => 'tj-post-' . $this->suffix,
			]
		);
		$this->post_id = (int) ( is_wp_error( $post_id ) ? 0 : $post_id );
	}

	public function test_create_category_succeeds(): void {
		$result = $this->journey->create_category(
			[
				'name'        => 'Fresh Cat',
				'slug'        => 'fresh-' . $this->suffix,
				'description' => 'A fresh category',
			]
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertIsInt( $result->data['id'] );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( 'fresh-' . $this->suffix, $result->data['slug'] );
	}

	public function test_create_category_requires_name_and_slug(): void {
		$result = $this->journey->create_category( [ 'name' => 'Only name' ] );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Missing required fields', $result->first_error() );
		$this->assertStringContainsString( 'slug', $result->first_error() );
	}

	public function test_update_category_whitelists_fields(): void {
		$cat_id = (int) Category::create(
			[
				'name' => 'Update Me',
				'slug' => 'update-' . $this->suffix,
			]
		);

		$result = $this->journey->update_category(
			$cat_id,
			[
				'name'        => 'Renamed',
				'space_count' => 9999, // should be silently dropped.
			]
		);

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( [ 'name' ], $result->data['updated'] );

		$row = Category::find( $cat_id );
		$this->assertSame( 'Renamed', $row->name );
	}

	public function test_delete_category_removes_row(): void {
		$cat_id = (int) Category::create(
			[
				'name' => 'Doomed',
				'slug' => 'doomed-' . $this->suffix,
			]
		);

		$result = $this->journey->delete_category( $cat_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $cat_id, $result->data['id'] );
		$this->assertNull( Category::find( $cat_id ) );
	}

	public function test_get_category_by_slug(): void {
		$slug = 'lookup-' . $this->suffix;
		$id   = (int) Category::create(
			[
				'name' => 'Lookup',
				'slug' => $slug,
			]
		);

		$result = $this->journey->get_category_by_slug( $slug );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $id, (int) $result->data['id'] );
		$this->assertSame( $slug, $result->data['slug'] );
	}

	public function test_list_top_level_categories_returns_items(): void {
		// The set_up() category is already top-level; just confirm the shape.
		$result = $this->journey->list_top_level_categories();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'name', $result->data['columns'] );
		$this->assertContains( 'slug', $result->data['columns'] );

		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( $this->category_id, $ids );
	}

	public function test_list_category_children_returns_only_children(): void {
		$child_id = (int) Category::create(
			[
				'name'      => 'Child Cat',
				'slug'      => 'child-' . $this->suffix,
				'parent_id' => $this->category_id,
			]
		);

		$result = $this->journey->list_category_children( $this->category_id );

		$this->assertTrue( $result->is_success() );
		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( $child_id, $ids );
		$this->assertNotContains( $this->category_id, $ids );
	}

	public function test_create_or_get_tag_creates_new(): void {
		$name = 'tj-new-tag-' . $this->suffix;

		$result = $this->journey->create_or_get_tag( $name );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['created'] );
		$this->assertGreaterThan( 0, $result->data['id'] );
	}

	public function test_create_or_get_tag_reuses_existing(): void {
		$name = 'tj-dup-tag-' . $this->suffix;

		$first = $this->journey->create_or_get_tag( $name );
		$this->assertTrue( $first->is_success() );
		$this->assertTrue( $first->data['created'] );

		$second = $this->journey->create_or_get_tag( $name );
		$this->assertTrue( $second->is_success() );
		$this->assertFalse( $second->data['created'] );
		$this->assertSame( $first->data['id'], $second->data['id'] );
	}

	public function test_attach_and_detach_tag(): void {
		$this->assertGreaterThan( 0, $this->post_id, 'Post fixture should exist.' );

		$tag_id = Tag::find_or_create( 'tj-attach-' . $this->suffix );

		$attach = $this->journey->attach_tag_to_post( $this->post_id, $tag_id );
		$this->assertTrue( $attach->is_success() );

		$list_after_attach = Tag::list_for_post( $this->post_id );
		$this->assertSame( 1, count( $list_after_attach ) );

		$detach = $this->journey->detach_tag_from_post( $this->post_id, $tag_id );
		$this->assertTrue( $detach->is_success() );

		$list_after_detach = Tag::list_for_post( $this->post_id );
		$this->assertSame( 0, count( $list_after_detach ) );
	}

	public function test_list_tags_for_post_after_attach(): void {
		$this->assertGreaterThan( 0, $this->post_id, 'Post fixture should exist.' );

		$tag_id = Tag::find_or_create( 'tj-list-' . $this->suffix );
		Tag::attach_to_post( $this->post_id, $tag_id );

		$result = $this->journey->list_tags_for_post( $this->post_id );

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( $tag_id, $ids );
	}

	public function test_search_tags_rejects_empty_query(): void {
		$result = $this->journey->search_tags( '' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'query', $result->first_error() );
	}

	public function test_search_tags_returns_matches(): void {
		$unique_name = 'zzsearch' . $this->suffix;
		Tag::find_or_create( $unique_name );

		$result = $this->journey->search_tags( 'zzsearch', 20 );

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$names = array_column( $result->data['items'], 'name' );
		$this->assertContains( $unique_name, $names );
	}
}
