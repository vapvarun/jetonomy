<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * A javascript: or data: address is refused like any other bad address and the
 * existing image is kept. Until 2.0.7 the route's 'format' => 'uri' ran
 * esc_url_raw() first, turning it into '' - which the handler reads as "clear".
 */
class UnsafeImageAddressTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function patch( string $route, array $params, int $user_id ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'PATCH', '/jetonomy/v1' . $route );
		$request->set_body_params( $params );
		return $this->server->dispatch( $request );
	}

	public function test_space_cover_refuses_unsafe_schemes_and_keeps_the_cover(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( 'jetonomy_manage_spaces' );
		$cat   = Category::create( array( 'name' => 'Cover Cat', 'slug' => 'cover-cat-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'Cover', 'slug' => 'cover-' . uniqid(), 'category_id' => $cat ) );

		$this->assertSame( 200, $this->patch( "/spaces/{$space}", array( 'cover_image' => 'https://example.com/cover.png' ), $admin )->get_status() );
		foreach ( array( 'javascript:alert(1)', 'data:text/html,hi' ) as $bad ) {
			$this->assertSame( 400, $this->patch( "/spaces/{$space}", array( 'cover_image' => $bad ), $admin )->get_status(), $bad );
			$this->assertSame( 'https://example.com/cover.png', Space::find( $space )->cover_image, $bad );
		}
		$this->assertSame( 200, $this->patch( "/spaces/{$space}", array( 'cover_image' => '' ), $admin )->get_status() );
		$this->assertSame( '', (string) Space::find( $space )->cover_image );
	}

	public function test_avatar_refuses_unsafe_schemes(): void {
		$user = self::factory()->user->create();
		$this->assertSame( 200, $this->patch( '/users/me', array( 'avatar_url' => 'https://example.com/me.png' ), $user )->get_status() );
		$this->assertSame( 400, $this->patch( '/users/me', array( 'avatar_url' => 'javascript:alert(1)' ), $user )->get_status() );
	}
}
