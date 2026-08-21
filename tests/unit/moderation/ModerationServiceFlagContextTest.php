<?php
namespace Jetonomy\Tests\Unit\Moderation;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Moderation\Moderation_Service;

/**
 * Batched render context for a page of moderation flags (1.9.4).
 *
 * Both moderation surfaces resolved this per row: views/moderation.php ran
 * Post/Reply::find(), a second find() for a reply's parent, Space::find() and
 * get_userdata() — four queries a row, ~100 on a full page — and
 * flag-card.php added its own permalink join on top. The cost scaled with page
 * size, not with anything a site could see, so it stayed invisible until
 * someone opened a busy queue.
 *
 * The query-count assertion is the point of this file. Correctness alone would
 * still pass if someone quietly reintroduced a per-row lookup, so the flatness
 * is asserted directly rather than trusted.
 */
class ModerationServiceFlagContextTest extends WP_UnitTestCase {

	private int $space_id;
	private int $author_id;
	private string $slug_suffix;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->slug_suffix = uniqid( 'msfc_', true );
		$cat               = (int) Category::create(
			[
				'name' => 'Flag context test',
				'slug' => 'cat-' . $this->slug_suffix,
			]
		);

		$this->space_id = (int) Space::create(
			[
				'category_id' => $cat,
				'title'       => 'Context Space',
				'slug'        => 's-' . $this->slug_suffix,
				'visibility'  => 'public',
			],
			0
		);

		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function test_resolves_a_flagged_post_with_its_space_and_permalink(): void {
		$post = $this->seed_post( 'Flagged topic' );
		$flag = $this->seed_flag( 'post', $post );

		$ctx = Moderation_Service::flag_context( [ $flag ], 'https://example.test/c' );

		$this->assertArrayHasKey( 'post:' . $post, $ctx );
		$entry = $ctx[ 'post:' . $post ];
		$this->assertSame( $post, (int) $entry->object->id );
		$this->assertSame( $this->space_id, (int) $entry->space->id );
		$this->assertStringContainsString( '/s/s-' . $this->slug_suffix . '/t/', $entry->permalink );
	}

	public function test_a_flagged_reply_resolves_the_space_of_its_parent_post(): void {
		// The interesting case: jt_replies has no space_id, so the space is
		// only knowable once the parent post is loaded. Getting the fetch order
		// wrong here silently drops every reply row from the queue.
		$post  = $this->seed_post( 'Parent topic' );
		$reply = (int) Reply::create(
			[
				'post_id'   => $post,
				'author_id' => $this->author_id,
				'content'   => '<p>Reported reply.</p>',
				'status'    => 'publish',
			]
		);
		$flag  = $this->seed_flag( 'reply', $reply );

		$ctx = Moderation_Service::flag_context( [ $flag ], 'https://example.test/c' );

		$this->assertArrayHasKey( 'reply:' . $reply, $ctx );
		$entry = $ctx[ 'reply:' . $reply ];
		$this->assertSame( $reply, (int) $entry->object->id );
		$this->assertSame( $this->space_id, (int) $entry->space->id );
		// A reply has no permalink of its own — it links to its parent thread.
		$this->assertStringContainsString( '/t/', $entry->permalink );
	}

	public function test_query_count_does_not_grow_with_the_number_of_flags(): void {
		global $wpdb;

		$few  = $this->seed_flags( 2 );
		$many = $this->seed_flags( 12 );

		wp_cache_flush();
		$before = $wpdb->num_queries;
		Moderation_Service::flag_context( $few, 'https://example.test/c' );
		$cost_few = $wpdb->num_queries - $before;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		Moderation_Service::flag_context( $many, 'https://example.test/c' );
		$cost_many = $wpdb->num_queries - $before;

		$this->assertSame(
			$cost_few,
			$cost_many,
			"Resolving 12 flags cost {$cost_many} queries vs {$cost_few} for 2 — the batch has become an N+1 again."
		);
	}

	public function test_flag_whose_object_was_deleted_is_absent_rather_than_fatal(): void {
		$post = $this->seed_post( 'Doomed topic' );
		$flag = $this->seed_flag( 'post', $post );
		Post::delete( $post );

		$ctx = Moderation_Service::flag_context( [ $flag ], 'https://example.test/c' );

		// Absent, not a null-shaped entry: the templates skip missing keys, so
		// a half-built row would render a card pointing at nothing.
		$this->assertArrayNotHasKey( 'post:' . $post, $ctx );
	}

	public function test_empty_input_returns_empty_without_querying(): void {
		global $wpdb;

		$before = $wpdb->num_queries;
		$this->assertSame( [], Moderation_Service::flag_context( [], 'https://example.test/c' ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * @param int $count
	 * @return object[]
	 */
	private function seed_flags( int $count ): array {
		$flags = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$post    = $this->seed_post( 'Topic ' . $i . ' ' . uniqid() );
			$flags[] = $this->seed_flag( 'post', $post );
		}

		return $flags;
	}

	private function seed_post( string $title ): int {
		return (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => $title,
				'content'   => '<p>Body.</p>',
				'status'    => 'publish',
			]
		);
	}

	private function seed_flag( string $type, int $object_id ): object {
		// A distinct reporter per flag, so the batched user prime is exercised
		// rather than trivially satisfied by one repeated id.
		$id = (int) Flag::create(
			[
				'object_type' => $type,
				'object_id'   => $object_id,
				'reporter_id' => self::factory()->user->create( [ 'role' => 'subscriber' ] ),
				'reason'      => 'spam',
				'description' => 'Test flag.',
				'status'      => 'pending',
			]
		);

		return Flag::find( $id );
	}
}
