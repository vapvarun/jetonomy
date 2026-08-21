<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Cache;
use Jetonomy\DB\Schema;
use Jetonomy\Models\BlockedUser;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

/**
 * Per-viewer state must never survive in a shared thread cache entry.
 *
 * Reply::get_threaded() caches under a key with no viewer component. When it
 * first shipped it cached the FINISHED tree, and the tree is built with
 * per-viewer scrubbing applied - block tombstones and private-reply
 * tombstones. Whoever primed the entry therefore decided what every later
 * reader saw until the TTL expired.
 *
 * Reproduced before the fix: a reply author primed a thread containing their
 * own private reply, and a LOGGED-OUT visitor read the private body straight
 * out of that cache entry. QA found it by inspection on Basecamp 10161156405
 * after the caching gate had reported 7/7 - none of those seven checks read
 * the same key as two different people, which is exactly why it got through.
 *
 * These tests do that: same thread, same key, different viewers.
 */
class ReplyThreadCacheViewerTest extends WP_UnitTestCase {

	private int $post_id;
	private int $space_id;
	private int $asker_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$suffix = uniqid( 'rtcv_', true );
		$cat    = (int) Category::create(
			[
				'name' => 'Cache viewer',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id = (int) Space::create(
			[
				'title'       => 'Cache viewer space',
				'slug'        => 's-' . $suffix,
				'category_id' => $cat,
				'visibility'  => 'public',
			]
		);
		$this->asker_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->post_id  = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->asker_id,
				'title'     => 'Cache viewer post',
				'content'   => '<p>Question body.</p>',
				'status'    => 'publish',
			]
		);
	}

	/**
	 * Body of one reply as a given viewer sees it, reading through the cache.
	 *
	 * @param int $viewer_id Viewer (0 = logged out).
	 * @param int $reply_id  Reply to look for.
	 */
	private function body_as( int $viewer_id, int $reply_id ): string {
		wp_set_current_user( $viewer_id );
		Cache::reset_memos();

		foreach ( Reply::get_threaded( $this->post_id ) as $node ) {
			if ( (int) $node->id === $reply_id ) {
				return (string) ( $node->content ?? '' );
			}
		}

		return '';
	}

	public function test_private_reply_is_not_served_to_a_guest_from_a_primed_cache(): void {
		$private_author = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$reply_id       = (int) Reply::create(
			[
				'post_id'    => $this->post_id,
				'author_id'  => $private_author,
				'content'    => '<p>SECRET-PRIVATE-BODY</p>',
				'status'     => 'publish',
				'is_private' => 1,
			]
		);

		// The author primes the entry - they are allowed to read it.
		$as_author = $this->body_as( $private_author, $reply_id );
		$this->assertStringContainsString( 'SECRET-PRIVATE-BODY', $as_author );

		// A logged-out visitor reads the SAME cache key.
		$as_guest = $this->body_as( 0, $reply_id );
		$this->assertStringNotContainsString(
			'SECRET-PRIVATE-BODY',
			$as_guest,
			'A guest read a private reply out of the cache entry the author primed.'
		);
	}

	public function test_a_guest_read_does_not_strip_the_authors_own_access(): void {
		// The mirror image, and the reason the fix clones before scrubbing:
		// if scrubbing mutated the cached objects in place, the guest's read
		// would write empty content into the shared entry and the author would
		// lose their own reply on the next view.
		$private_author = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$reply_id       = (int) Reply::create(
			[
				'post_id'    => $this->post_id,
				'author_id'  => $private_author,
				'content'    => '<p>SECRET-PRIVATE-BODY</p>',
				'status'     => 'publish',
				'is_private' => 1,
			]
		);

		$this->body_as( $private_author, $reply_id );
		$this->body_as( 0, $reply_id );
		$after = $this->body_as( $private_author, $reply_id );

		$this->assertStringContainsString(
			'SECRET-PRIVATE-BODY',
			$after,
			"The guest's read mutated the shared cache entry and cost the author their own reply."
		);
	}

	public function test_a_new_block_applies_to_an_already_warm_thread(): void {
		$blocker = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$noisy   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$reply   = (int) Reply::create(
			[
				'post_id'   => $this->post_id,
				'author_id' => $noisy,
				'content'   => '<p>NOISY-BODY</p>',
				'status'    => 'publish',
			]
		);

		// Warm the thread while NOT blocking.
		$before = $this->body_as( $blocker, $reply );
		$this->assertStringContainsString( 'NOISY-BODY', $before );

		BlockedUser::block( $blocker, $noisy );

		// Must take effect on the next read, not when the entry expires.
		$after = $this->body_as( $blocker, $reply );
		$this->assertStringNotContainsString(
			'NOISY-BODY',
			$after,
			'A block did not reach an already-cached thread - the viewer waits out the TTL.'
		);
	}

	public function test_blocking_is_one_way_and_does_not_reach_other_viewers(): void {
		$blocker  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$bystander = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$noisy    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$reply    = (int) Reply::create(
			[
				'post_id'   => $this->post_id,
				'author_id' => $noisy,
				'content'   => '<p>NOISY-BODY</p>',
				'status'    => 'publish',
			]
		);

		BlockedUser::block( $blocker, $noisy );

		// The blocker primes the entry with their tombstone applied.
		$this->assertStringNotContainsString( 'NOISY-BODY', $this->body_as( $blocker, $reply ) );

		// Somebody who blocked nobody must still see the content.
		$this->assertStringContainsString(
			'NOISY-BODY',
			$this->body_as( $bystander, $reply ),
			"One viewer's block tombstone bled into another viewer's read."
		);
	}
}
