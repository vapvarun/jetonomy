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
