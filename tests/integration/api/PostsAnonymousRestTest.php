<?php
/**
 * Integration test: prepare_post() / prepare_reply() anonymous masking (1.6.0 Pro).
 *
 * A post/reply flagged is_anonymous must return the masked identity
 * (author_id 0, "Anonymous", empty login/avatar/url) over REST to a
 * non-admin caller, on both the single-item GET (per-item lookup path)
 * and the collection GET (enrich_with_author batch path). Real authors
 * must be unaffected.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;

class PostsAnonymousRestTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $author_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat            = Category::create(
			array(
				'name' => 'AnonRest Cat',
				'slug' => 'anonrest-cat-' . uniqid(),
			)
		);
		$this->space_id = Space::create(
			array(
				'title'       => 'AnonRest Space',
				'slug'        => 'anonrest-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);

		$this->author_id = self::factory()->user->create(
			array(
				'display_name' => 'Real Name',
				'role'         => 'subscriber',
			)
		);
		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->space_id, $this->author_id, 'member' );
		SpaceMember::add( $this->space_id, $this->member_id, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_flagged_post_returns_masked_author_to_member(): void {
		$post_id = Post::create(
			array(
				'space_id'     => $this->space_id,
				'author_id'    => $this->author_id,
				'title'        => 'Flagged topic',
				'content'      => 'Body',
				'status'       => 'publish',
				'is_anonymous' => 1,
			)
		);

		wp_set_current_user( $this->member_id );
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$post_id}" ) )->get_data();

		$this->assertSame( 0, $data['author_id'] );
		$this->assertSame( 'Anonymous', $data['author_name'] );
		$this->assertSame( '', $data['author_login'] );
		$this->assertSame( '', $data['author_avatar'] );
		$this->assertSame( '', $data['profile_url'] );
		$this->assertNotSame( 'Real Name', $data['author_name'] );
	}

	public function test_unflagged_post_returns_real_author_no_regression(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Normal topic',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		wp_set_current_user( $this->member_id );
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$post_id}" ) )->get_data();

		$this->assertSame( $this->author_id, $data['author_id'] );
		$this->assertSame( 'Real Name', $data['author_name'] );
	}

	/**
	 * Regression: Author::for_display() also returns id=0 for content whose
	 * REAL author_id is 0 (deleted-user content — Privacy::on_user_delete()
	 * zeroes author_id but leaves is_anonymous=0). The masking override in
	 * prepare_post() must NOT fire in that case; the pre-existing "Anonymous"
	 * display-name fallback (for a null get_userdata()) must be preserved,
	 * not overwritten with an empty string.
	 */
	public function test_deleted_user_post_falls_back_to_anonymous_label_not_empty(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Orphaned topic',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		// Simulate Privacy::on_user_delete(): zero the real author_id while
		// leaving is_anonymous=0 (not a masking case).
		global $wpdb;
		$wpdb->update( \Jetonomy\table( 'posts' ), array( 'author_id' => 0 ), array( 'id' => $post_id ) );

		wp_set_current_user( $this->member_id );
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$post_id}" ) )->get_data();

		$this->assertSame( 0, $data['author_id'] );
		// A deleted account renders distinctly from a deliberately-anonymous Pro
		// post: "[deleted]", never "Anonymous" and never blank (Author::for_display).
		$this->assertSame( '[deleted]', $data['author_name'] );
		$this->assertNotSame( '', $data['author_name'] );
	}

	/**
	 * Same regression for replies: deleted-user reply (author_id=0,
	 * is_anonymous=0) must keep the "Anonymous" fallback label, not an
	 * empty string, in the collection batch-enrich path.
	 */
	public function test_deleted_user_reply_falls_back_to_anonymous_label_not_empty(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Topic with orphaned reply',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$reply_id = Reply::create(
			array(
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => 'Orphaned reply',
			)
		);

		global $wpdb;
		$wpdb->update( \Jetonomy\table( 'replies' ), array( 'author_id' => 0 ), array( 'id' => $reply_id ) );

		wp_set_current_user( $this->member_id );
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$post_id}/replies" ) )->get_data();

		$this->assertNotEmpty( $data['data'] );
		$reply = $data['data'][0];
		$this->assertSame( 0, $reply['author_id'] );
		// Deleted account -> "[deleted]", distinct from anonymous masking.
		$this->assertSame( '[deleted]', $reply['author_name'] );
		$this->assertNotSame( '', $reply['author_name'] );
	}

	public function test_flagged_reply_masked_in_collection_batch_path(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Topic with replies',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		Reply::create(
			array(
				'post_id'      => $post_id,
				'author_id'    => $this->author_id,
				'content'      => 'Flagged reply',
				'is_anonymous' => 1,
			)
		);

		wp_set_current_user( $this->member_id );
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$post_id}/replies" ) )->get_data();

		$this->assertNotEmpty( $data['data'] );
		$reply = $data['data'][0];
		$this->assertSame( 0, $reply['author_id'] );
		$this->assertSame( 'Anonymous', $reply['author_name'] );
		$this->assertSame( '', $reply['author_login'] );
		$this->assertNotSame( 'Real Name', $reply['author_name'] );
	}
}
