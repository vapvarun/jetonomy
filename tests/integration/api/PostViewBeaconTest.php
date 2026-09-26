<?php
/**
 * Integration test: POST /posts/{id}/view is the single topic view counter
 * (Basecamp 10344051299).
 *
 * Topic page responses no longer count views or set a cookie (so page caches
 * store them); the page's beacon counts instead. The server must count one
 * view per IP per topic per window, refuse missing / unpublished / unreadable
 * topics with one indistinguishable 404, and GET /posts/{id} must not count.
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
use Jetonomy\Models\Post;

class PostViewBeaconTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $cat_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->cat_id    = Category::create(
			array(
				'name' => 'View Cat',
				'slug' => 'view-cat-' . uniqid(),
			)
		);
		$this->author_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function topic( string $visibility = 'public', string $status = 'publish' ): int {
		$space = Space::create(
			array(
				'title'       => 'View Space',
				'slug'        => 'view-space-' . uniqid(),
				'category_id' => $this->cat_id,
				'visibility'  => $visibility,
				'type'        => 'forum',
			)
		);
		return (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $this->author_id,
				'title'     => 'View topic',
				'content'   => 'Body',
				'status'    => $status,
			)
		);
	}

	private function views( int $id ): int {
		global $wpdb;
		// Straight from the row: increment_view_count() deliberately leaves the post cache warm.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT view_count FROM ' . \Jetonomy\table( 'posts' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	private function beacon( int $id ) {
		return $this->server->dispatch( new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$id}/view" ) );
	}

	public function test_burst_counts_once_per_ip_window(): void {
		$id = $this->topic();

		$first = $this->beacon( $id );
		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $first->get_data()['counted'] );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertFalse( $this->beacon( $id )->get_data()['counted'] );
		}
		$this->assertSame( 1, $this->views( $id ) );

		// Another visitor is a new view.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertTrue( $this->beacon( $id )->get_data()['counted'] );
		$this->assertSame( 2, $this->views( $id ) );
	}

	public function test_get_item_does_not_count(): void {
		$id = $this->topic();
		$this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$id}" ) );
		$this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$id}" ) );
		$this->assertSame( 0, $this->views( $id ) );
	}

	public function test_missing_unpublished_and_unreadable_topics_share_one_404(): void {
		$private = $this->topic( 'private' );
		$draft   = $this->topic( 'public', 'draft' );

		$shapes = array();
		foreach ( array( 999999, $private, $draft ) as $id ) {
			$res      = $this->beacon( $id );
			$shapes[] = array( $res->get_status(), $res->get_data()['code'] ?? '', $res->get_data()['message'] ?? '' );
		}

		$this->assertSame( 404, $shapes[0][0] );
		$this->assertSame( $shapes[0], $shapes[1], 'A private topic must answer exactly like a missing one.' );
		$this->assertSame( $shapes[0], $shapes[2], 'An unpublished topic must answer exactly like a missing one.' );
		$this->assertSame( 0, $this->views( $private ) );
		$this->assertSame( 0, $this->views( $draft ) );
	}
}
