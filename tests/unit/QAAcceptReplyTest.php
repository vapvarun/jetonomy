<?php
/**
 * Regression test: Q&A accept-reply invariant.
 *
 * A Q&A post must have at most one accepted reply at any time.
 * Accepting a second reply must clear the first.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

class QAAcceptReplyTest extends WP_UnitTestCase {

	private int $post_id;
	private int $reply_a_id;
	private int $reply_b_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id   = Category::create( array( 'name' => 'QA', 'slug' => 'qa-' . uniqid() ) );
		$space_id = Space::create(
			array(
				'title'       => 'QA Space',
				'slug'        => 'qa-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
				'type'        => 'qa',
			)
		);

		$this->post_id = Post::create(
			array(
				'space_id' => $space_id,
				'title'    => 'QA Test Post',
				'slug'     => 'qa-test-post-' . uniqid(),
				'content'  => '<p>What is the answer?</p>',
			)
		);

		$this->reply_a_id = Reply::create(
			array(
				'post_id' => $this->post_id,
				'content' => '<p>First answer.</p>',
				'status'  => 'publish',
			)
		);

		$this->reply_b_id = Reply::create(
			array(
				'post_id' => $this->post_id,
				'content' => '<p>Better answer.</p>',
				'status'  => 'publish',
			)
		);
	}

	/**
	 * Accepting a second reply must clear the first.
	 * The replies table must never have two rows with is_accepted=1 for the same post.
	 */
	public function test_accepting_second_reply_clears_first(): void {
		// Accept reply A first.
		Reply::mark_accepted( $this->reply_a_id );

		$reply_a = Reply::find( $this->reply_a_id );
		$this->assertEquals( 1, (int) $reply_a->is_accepted, 'Reply A should be accepted after first mark_accepted call.' );

		// Now accept reply B — this must clear A.
		Reply::mark_accepted( $this->reply_b_id );

		$reply_a_after = Reply::find( $this->reply_a_id );
		$reply_b_after = Reply::find( $this->reply_b_id );

		$this->assertEquals( 0, (int) $reply_a_after->is_accepted, 'Reply A must have is_accepted=0 after reply B is accepted.' );
		$this->assertEquals( 1, (int) $reply_b_after->is_accepted, 'Reply B must have is_accepted=1 after being accepted.' );
	}

	/**
	 * Database must never contain two accepted replies for the same post.
	 */
	public function test_only_one_accepted_reply_exists_in_db(): void {
		Reply::mark_accepted( $this->reply_a_id );
		Reply::mark_accepted( $this->reply_b_id );

		global $wpdb;
		$table = $wpdb->prefix . 'jt_replies';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND is_accepted = 1",
				$this->post_id
			)
		);

		$this->assertEquals( 1, $count, 'Exactly one reply per post may have is_accepted=1.' );
	}

	/**
	 * After accepting reply B, Post::accept_reply() should point accepted_reply_id to B.
	 * This mirrors the REST controller pattern at class-replies-controller.php:564-565.
	 */
	public function test_post_accepted_reply_id_updated(): void {
		Reply::mark_accepted( $this->reply_a_id );
		Post::accept_reply( $this->post_id, $this->reply_a_id );

		Reply::mark_accepted( $this->reply_b_id );
		Post::accept_reply( $this->post_id, $this->reply_b_id );

		$post = Post::find( $this->post_id );
		$this->assertEquals( $this->reply_b_id, (int) $post->accepted_reply_id, 'Post.accepted_reply_id must point to reply B after re-accept.' );
	}
}
