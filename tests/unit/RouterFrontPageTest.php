<?php
/**
 * Verifies the "Community as Homepage" request handling is strictly additive:
 * `suppress_default_query()` leaves every ordinary request (posts, pages,
 * feeds, pagination, search) untouched, and only rewrites a request that
 * already carries a `jetonomy_route` — stripping the slug lookups WP_Query
 * would fail on and returning an empty result set for handle_request() to
 * render over. `is_mapped_front_page()` is gated purely on the setting.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Router;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Router::suppress_default_query
 * @covers \Jetonomy\Router::is_mapped_front_page
 */
class RouterFrontPageTest extends WP_UnitTestCase {

	private Router $router;

	public function set_up(): void {
		parent::set_up();
		$this->router = new Router();
	}

	public function tear_down(): void {
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	public function test_mapped_front_page_off_by_default(): void {
		$this->assertFalse( $this->router->is_mapped_front_page() );
	}

	public function test_mapped_front_page_disabled_explicitly(): void {
		update_option( 'jetonomy_settings', array( 'front_page' => false ) );
		$this->assertFalse( $this->router->is_mapped_front_page() );
	}

	/**
	 * A request that carries no jetonomy_route must pass through untouched —
	 * posts, pages, feeds, pagination and search are none of Jetonomy's business.
	 *
	 * @dataProvider non_community_requests
	 */
	public function test_non_community_requests_pass_through_untouched( array $vars ): void {
		$this->assertSame( $vars, $this->router->suppress_default_query( $vars ) );
	}

	public function non_community_requests(): array {
		return array(
			'blog pagination' => array( array( 'paged' => 2 ) ),
			'site feed'       => array( array( 'feed' => 'feed' ) ),
			'single post'     => array( array( 'name' => 'hello-world' ) ),
			'static page'     => array( array( 'pagename' => 'about' ) ),
			'post by id'      => array( array( 'p' => 123 ) ),
			'search'          => array( array( 's' => 'wordpress' ) ),
		);
	}

	/**
	 * A request that already resolved to a jetonomy_route gets its slug-based
	 * lookups stripped (no backing post exists) and an empty result set, so
	 * WP_Query does not try and fail to find a post before handle_request()
	 * renders the community output.
	 *
	 * @dataProvider community_route_requests
	 */
	public function test_community_route_request_is_suppressed( array $vars, string $expected_route ): void {
		$out = $this->router->suppress_default_query( $vars );

		$this->assertSame( $expected_route, $out['jetonomy_route'] );
		$this->assertArrayNotHasKey( 'name', $out );
		$this->assertArrayNotHasKey( 'pagename', $out );
		$this->assertArrayNotHasKey( 'page', $out );
		$this->assertSame( array( 0 ), $out['post__in'] );
	}

	public function community_route_requests(): array {
		return array(
			'community home route'  => array(
				array( 'jetonomy_route' => 'home' ),
				'home',
			),
			'community space route' => array(
				array(
					'jetonomy_route' => 'space',
					'name'           => 'general',
				),
				'space',
			),
			'community topic route' => array(
				array(
					'jetonomy_route'      => 'post',
					'jetonomy_space_slug' => 'general',
					'jetonomy_slug'       => 'welcome',
					'pagename'            => 'welcome',
				),
				'post',
			),
		);
	}
}
