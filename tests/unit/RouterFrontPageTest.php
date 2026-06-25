<?php
/**
 * Verifies the "Community as Homepage" request filter is strictly additive:
 * it injects the home route ONLY for the bare front-page request (empty
 * query vars) with the setting enabled, and never touches any request that
 * already carries query vars — posts, pages, feeds, pagination, or any
 * /{base}/* community route.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Router;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Router::maybe_serve_front_page
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

	private function enable_front_page(): void {
		$settings               = get_option( 'jetonomy_settings', array() );
		$settings['front_page'] = true;
		update_option( 'jetonomy_settings', $settings );
	}

	public function test_off_by_default_front_page_untouched(): void {
		$this->assertSame( array(), $this->router->maybe_serve_front_page( array() ) );
	}

	public function test_option_disabled_explicitly_front_page_untouched(): void {
		update_option( 'jetonomy_settings', array( 'front_page' => false ) );
		$this->assertSame( array(), $this->router->maybe_serve_front_page( array() ) );
	}

	public function test_option_enabled_injects_home_route_on_front_page(): void {
		$this->enable_front_page();
		$this->assertSame(
			array( 'jetonomy_route' => 'home' ),
			$this->router->maybe_serve_front_page( array() )
		);
	}

	/**
	 * Any request already carrying query vars must pass through untouched,
	 * even with the option enabled.
	 *
	 * @dataProvider non_front_page_requests
	 */
	public function test_requests_with_vars_pass_through_untouched( array $vars ): void {
		$this->enable_front_page();
		$this->assertSame( $vars, $this->router->maybe_serve_front_page( $vars ) );
	}

	public function non_front_page_requests(): array {
		return array(
			'blog pagination'        => array( array( 'paged' => 2 ) ),
			'site feed'              => array( array( 'feed' => 'feed' ) ),
			'single post'            => array( array( 'name' => 'hello-world' ) ),
			'static page'            => array( array( 'pagename' => 'about' ) ),
			'post by id'             => array( array( 'p' => 123 ) ),
			'search'                 => array( array( 's' => 'wordpress' ) ),
			'community home route'   => array( array( 'jetonomy_route' => 'home' ) ),
			'community space route'  => array( array( 'jetonomy_route' => 'space', 'jetonomy_slug' => 'general' ) ),
			'community topic route'  => array(
				array(
					'jetonomy_route'      => 'post',
					'jetonomy_space_slug' => 'general',
					'jetonomy_slug'       => 'welcome',
				),
			),
		);
	}
}
