<?php
/**
 * Integration test: prepare_post() bookmark + vote context (1.6.0 mobile API).
 *
 * GET /posts/{id} must expose viewer-relative is_bookmarked + viewer_vote
 * (-1|0|1), additive and null-safe for logged-out callers.
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
use Jetonomy\Models\Vote;
use Jetonomy\Models\Bookmark;

class PostShapeTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $post_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat            = Category::create(
			array(
				'name' => 'PostShape Cat',
				'slug' => 'postshape-cat-' . uniqid(),
			)
		);
		$this->space_id = Space::create(
			array(
				'title'       => 'PostShape Space',
				'slug'        => 'postshape-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);

		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->space_id, $this->member_id, 'member' );

		$this->post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->member_id,
				'title'     => 'Shape post',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_exposes_viewer_bookmark_and_vote(): void {
		wp_set_current_user( $this->member_id );
		Vote::cast( $this->member_id, 'post', $this->post_id, 1 );
		Bookmark::toggle( $this->member_id, $this->post_id );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}" ) )->get_data();

		$this->assertArrayHasKey( 'is_bookmarked', $data );
		$this->assertArrayHasKey( 'viewer_vote', $data );
		$this->assertTrue( $data['is_bookmarked'] );
		$this->assertSame( 1, $data['viewer_vote'] );
	}

	public function test_safe_defaults_for_anon(): void {
		wp_set_current_user( 0 );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}" ) )->get_data();

		$this->assertFalse( $data['is_bookmarked'] );
		$this->assertSame( 0, $data['viewer_vote'] );
	}
}
