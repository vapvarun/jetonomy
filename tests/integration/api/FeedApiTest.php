<?php
/**
 * Integration test: GET /jetonomy/v1/feed (1.6.0 mobile API).
 *
 * The global cross-space home feed must paginate, accept the hot|new|top sort
 * enum, reject unknown sorts at the schema layer, and gate private-space posts
 * out of a logged-out viewer's slice (visibility enforced in SQL).
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

class FeedApiTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $public_space_id;
	private int $private_space_id;
	private int $public_post_id;
	private int $private_post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat = Category::create(
			array(
				'name' => 'Feed Cat',
				'slug' => 'feed-cat-' . uniqid(),
			)
		);

		$this->public_space_id = Space::create(
			array(
				'title'       => 'Feed Public Space',
				'slug'        => 'feed-public-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);
		$this->private_space_id = Space::create(
			array(
				'title'       => 'Feed Private Space',
				'slug'        => 'feed-private-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'private',
				'type'        => 'forum',
			)
		);

		$this->public_post_id = Post::create(
			array(
				'space_id'  => $this->public_space_id,
				'author_id' => 1,
				'title'     => 'Public feed post',
				'content'   => 'Body public',
				'status'    => 'publish',
			)
		);
		$this->private_post_id = Post::create(
			array(
				'space_id'  => $this->private_space_id,
				'author_id' => 1,
				'title'     => 'Private feed post',
				'content'   => 'Body private',
				'status'    => 'publish',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_returns_paginated_feed_and_excludes_private_space_for_anon(): void {
		wp_set_current_user( 0 );

		$res = $this->server->dispatch( new WP_REST_Request( 'GET', '/jetonomy/v1/feed' ) );
		$this->assertSame( 200, $res->get_status() );

		$data = $res->get_data();
		$this->assertArrayHasKey( 'data', $data );

		$ids = array_map( static fn( $p ) => (int) $p['id'], $data['data'] );
		$this->assertContains( $this->public_post_id, $ids );
		$this->assertNotContains( $this->private_post_id, $ids, 'Anon feed must not leak private-space posts.' );

		$headers = $res->get_headers();
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
		$this->assertGreaterThanOrEqual( 1, (int) $headers['X-WP-TotalPages'] );
	}

	public function test_accepts_each_sort_value(): void {
		foreach ( array( 'hot', 'new', 'top' ) as $sort ) {
			$req = new WP_REST_Request( 'GET', '/jetonomy/v1/feed' );
			$req->set_param( 'sort', $sort );
			$this->assertSame( 200, $this->server->dispatch( $req )->get_status(), "sort={$sort}" );
		}
	}

	public function test_rejects_unknown_sort(): void {
		$req = new WP_REST_Request( 'GET', '/jetonomy/v1/feed' );
		$req->set_param( 'sort', 'banana' );
		$this->assertSame( 400, $this->server->dispatch( $req )->get_status() );
	}

	public function test_member_sees_private_space_posts(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->private_space_id, $member, 'member' );
		wp_set_current_user( $member );

		$res = $this->server->dispatch( new WP_REST_Request( 'GET', '/jetonomy/v1/feed' ) );
		$ids = array_map( static fn( $p ) => (int) $p['id'], $res->get_data()['data'] );
		$this->assertContains( $this->private_post_id, $ids );
	}
}
