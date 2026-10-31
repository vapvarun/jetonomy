<?php
namespace Jetonomy\Tests\Unit\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;

/**
 * A title longer than the varchar(255) column is a clear 400 on create and
 * update, not a failed INSERT surfacing as 500 jetonomy_create_failed
 * (2.0.7 walk).
 */
class PostTitleLengthTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$cat         = Category::create( array( 'name' => 'TL', 'slug' => 'tl-' . uniqid() ) );
		$this->space = Space::create( array( 'title' => 'TL', 'slug' => 'tl-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function create( string $title ) {
		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/spaces/{$this->space}/posts" );
		$request->set_param( 'title', $title );
		$request->set_param( 'content', 'Body text long enough to post.' );
		return rest_do_request( $request );
	}

	public function test_create_rejects_an_over_long_title_with_400(): void {
		$response = $this->create( str_repeat( 'x', Post::TITLE_MAX_LENGTH + 1 ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_title_too_long', $response->get_data()['code'] );
	}

	public function test_create_accepts_a_title_at_the_limit_in_multibyte_text(): void {
		$this->assertSame( 201, $this->create( str_repeat( 'ب', Post::TITLE_MAX_LENGTH ) )->get_status() );
	}

	public function test_update_rejects_an_over_long_title_with_400(): void {
		$id      = (int) $this->create( 'Short title' )->get_data()['id'];
		$request = new WP_REST_Request( 'PATCH', "/jetonomy/v1/posts/{$id}" );
		$request->set_param( 'title', str_repeat( 'x', Post::TITLE_MAX_LENGTH + 1 ) );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Short title', Post::find( $id )->title );
	}

	public function test_model_create_refuses_an_over_long_title(): void {
		$result = Post::create( array( 'space_id' => $this->space, 'author_id' => get_current_user_id(), 'title' => str_repeat( 'x', 300 ), 'content' => 'x' ) );
		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
