<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;

/**
 * GET /spaces/{id}/posts honours per_page (core's name) and pages with the
 * space's own page size: per_page=1 answered every topic, and page 2 under a
 * page size other than 20 started at offset 20 (2.0.7 smoke c-member-space-pagination.15).
 */
class SpacePostsPerPageTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$author      = self::factory()->user->create();
		$cat         = Category::create( array( 'name' => 'PP', 'slug' => 'pp-' . uniqid() ) );
		$this->space = Space::create( array( 'title' => 'PP', 'slug' => 'pp-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		for ( $i = 1; $i <= 7; $i++ ) {
			Post::create( array( 'space_id' => $this->space, 'author_id' => $author, 'title' => "Topic {$i}", 'content' => 'x', 'status' => 'publish' ) );
		}
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	private function ids( array $query ): array {
		$request = new WP_REST_Request( 'GET', "/jetonomy/v1/spaces/{$this->space}/posts" );
		$request->set_query_params( $query );
		$data = rest_do_request( $request )->get_data();
		return array_map( static fn( $p ) => (int) ( is_array( $p ) ? $p['id'] : $p->id ), $data['items'] ?? $data['posts'] ?? $data['data'] ?? array() );
	}

	public function test_per_page_limits_and_pages_do_not_overlap(): void {
		$first  = $this->ids( array( 'per_page' => 3 ) );
		$second = $this->ids( array( 'per_page' => 3, 'page' => 2 ) );
		$this->assertCount( 3, $first );
		$this->assertCount( 3, $second );
		$this->assertSame( array(), array_intersect( $first, $second ) );
	}

	public function test_page_two_uses_the_space_page_size(): void {
		update_option( 'jetonomy_settings', array_merge( (array) get_option( 'jetonomy_settings', array() ), array( 'posts_per_page' => 5 ) ) );
		$this->assertCount( 5, $this->ids( array() ) );
		$this->assertCount( 2, $this->ids( array( 'page' => 2 ) ) );
	}
}
