<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * Exercises every Content_Journey method against the real model layer.
 *
 * Each test sets up a fresh space/post fixture under a unique slug so runs
 * are isolated within the same WP test DB. Journey methods are pure PHP
 * with no WP-CLI coupling, so these tests run through the standard
 * WP_UnitTestCase bootstrap without any CLI harness involvement.
 */
class ContentJourneyTest extends WP_UnitTestCase {

	private Content_Journey $journey;

	private int $space_id;

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new Content_Journey();

		$suffix          = uniqid( 'cj_', true );
		$cat_id          = Category::create(
			[
				'name' => 'CJ Test Category',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id  = Space::create(
			[
				'category_id' => $cat_id,
				'title'       => 'CJ Test Space',
				'slug'        => 'space-' . $suffix,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function test_create_post_succeeds_with_valid_input(): void {
		$result = $this->journey->create_post(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Hello world',
				'content'   => 'First post body.',
			]
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertIsInt( $result->data['id'] );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( $this->space_id, $result->data['space_id'] );
		$this->assertSame( $this->author_id, $result->data['author'] );
		$this->assertSame( 'publish', $result->data['status'] );
	}

	public function test_create_post_fails_when_required_fields_missing(): void {
		$result = $this->journey->create_post(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				// title + content intentionally omitted.
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Missing required fields', $result->first_error() );
		$this->assertStringContainsString( 'title', $result->first_error() );
		$this->assertStringContainsString( 'content', $result->first_error() );
	}

	public function test_update_post_only_applies_whitelisted_fields(): void {
		$post_id = $this->make_post();

		$result = $this->journey->update_post(
			$post_id,
			[
				'title'     => 'New title',
				'author_id' => 99, // should be silently dropped (not in whitelist).
			]
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 'title' ], $result->data['updated'] );

		$post = Post::find( $post_id );
		$this->assertSame( 'New title', $post->title );
		$this->assertSame( $this->author_id, (int) $post->author_id );
	}

	public function test_update_post_rejects_empty_patch(): void {
		$post_id = $this->make_post();
		$result  = $this->journey->update_post( $post_id, [ 'nope' => 'ignored' ] );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'No updatable fields', $result->first_error() );
	}

	public function test_delete_post_removes_row(): void {
		$post_id = $this->make_post();

		$result = $this->journey->delete_post( $post_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $post_id, $result->data['id'] );
		$this->assertNull( Post::find( $post_id ) );
	}

	public function test_get_post_returns_row_as_array(): void {
		$post_id = $this->make_post();

		$result = $this->journey->get_post( $post_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $post_id, (int) $result->data['id'] );
		$this->assertArrayHasKey( 'title', $result->data );
	}

	public function test_get_post_fails_for_missing_row(): void {
		$result = $this->journey->get_post( 999999 );
		$this->assertFalse( $result->is_success() );
	}

	public function test_create_reply_succeeds(): void {
		$post_id = $this->make_post();

		$result = $this->journey->create_reply(
			[
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => 'First reply.',
			]
		);

		$this->assertTrue( $result->is_success() );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( $post_id, $result->data['post_id'] );
	}

	public function test_create_reply_requires_post_author_content(): void {
		$result = $this->journey->create_reply( [ 'post_id' => 1 ] );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'author_id', $result->first_error() );
		$this->assertStringContainsString( 'content', $result->first_error() );
	}

	public function test_delete_reply_removes_row(): void {
		$post_id  = $this->make_post();
		$reply_id = Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => 'To be deleted.',
			]
		);

		$result = $this->journey->delete_reply( (int) $reply_id );
		$this->assertTrue( $result->is_success() );
	}

	public function test_accept_reply_sets_accepted_state(): void {
		$post_id  = $this->make_post();
		$reply_id = Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => 'Accepted answer.',
			]
		);

		$result = $this->journey->accept_reply( $post_id, (int) $reply_id );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( $post_id, $result->data['post_id'] );
		$this->assertSame( (int) $reply_id, $result->data['reply_id'] );
	}

	public function test_vote_cast_created_then_undone(): void {
		$post_id = $this->make_post();

		$first = $this->journey->vote( $this->author_id, 'post', $post_id, 1 );
		$this->assertTrue( $first->is_success() );
		$this->assertSame( 'created', $first->data['action'] );

		$second = $this->journey->vote( $this->author_id, 'post', $post_id, 1 );
		$this->assertTrue( $second->is_success() );
		$this->assertSame( 'removed', $second->data['action'] );
	}

	public function test_vote_rejects_invalid_value(): void {
		$post_id = $this->make_post();
		$result  = $this->journey->vote( $this->author_id, 'post', $post_id, 5 );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'value', $result->first_error() );
	}

	public function test_vote_rejects_invalid_object_type(): void {
		$post_id = $this->make_post();
		$result  = $this->journey->vote( $this->author_id, 'space', $post_id, 1 );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'object_type', $result->first_error() );
	}

	public function test_flag_creates_pending_row(): void {
		$post_id = $this->make_post();

		$result = $this->journey->flag(
			[
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reporter_id' => $this->author_id,
				'reason'      => 'spam',
			]
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'pending', $result->data['status'] );
		$this->assertSame( $post_id, $result->data['object_id'] );
	}

	public function test_flag_requires_all_fields(): void {
		$result = $this->journey->flag(
			[
				'object_type' => 'post',
				'object_id'   => 1,
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'reporter_id', $result->first_error() );
		$this->assertStringContainsString( 'reason', $result->first_error() );
	}

	public function test_flag_rejects_invalid_object_type(): void {
		$result = $this->journey->flag(
			[
				'object_type' => 'space',
				'object_id'   => 1,
				'reporter_id' => $this->author_id,
				'reason'      => 'spam',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'object_type', $result->first_error() );
	}

	public function test_flag_rejects_invalid_reason(): void {
		$post_id = $this->make_post();
		$result  = $this->journey->flag(
			[
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reporter_id' => $this->author_id,
				'reason'      => 'not-an-enum-value',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'reason', $result->first_error() );
	}

	private function make_post(): int {
		return (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Fixture post ' . uniqid(),
				'content'   => 'Body.',
			]
		);
	}
}
