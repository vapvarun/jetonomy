<?php
/**
 * Pro paging args: a zero, negative or non-numeric page / per_page / limit
 * falls back to the route default instead of being read without its minus
 * sign (absint turned page=-3 into page 3 and per_page=-5 into 5 rows).
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;

class ProPagingArgsFallbackTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		update_option( 'jetonomy_pro_extensions', array( 'custom-badges', 'private-messaging', 'analytics' ) );
		foreach ( array(
			\Jetonomy_Pro\Extensions\Custom_Badges\Extension::class,
			\Jetonomy_Pro\Extensions\Private_Messaging\Extension::class,
			\Jetonomy_Pro\Extensions\Analytics\Extension::class,
		) as $class ) {
			$ext = new $class();
			$ext->activate();
			$ext->boot();
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: int, 2: int}>
	 */
	public function badge_paging_cases(): array {
		return array(
			'negative page'         => array( array( 'page' => '-3' ), 1, 25 ),
			'negative per_page'     => array( array( 'per_page' => '-5' ), 1, 25 ),
			'non-numeric per_page'  => array( array( 'per_page' => 'abc' ), 1, 25 ),
			'zero per_page'         => array( array( 'per_page' => '0' ), 1, 25 ),
			'per_page above max'    => array( array( 'per_page' => '101' ), 1, 100 ),
			'valid values are kept' => array( array( 'page' => '2', 'per_page' => '2' ), 2, 2 ),
		);
	}

	/**
	 * @dataProvider badge_paging_cases
	 *
	 * @param array<string, string> $params        Query params.
	 * @param int                   $want_page     Expected meta.page.
	 * @param int                   $want_per_page Expected meta.per_page.
	 */
	public function test_badges_paging_falls_back_or_clamps( array $params, int $want_page, int $want_per_page ): void {
		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/badges' );
		$request->set_query_params( $params );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$meta = $response->get_data()['meta'];
		$this->assertSame( $want_page, $meta['page'] );
		$this->assertSame( $want_per_page, $meta['per_page'] );
	}

	/**
	 * Every paging arg in the class keeps the sign when sanitized, so its
	 * handler can fall back on < 1 instead of serving the absolute value.
	 */
	public function test_paging_args_keep_the_minus_sign_for_the_handler_fallback(): void {
		$routes = $this->server->get_routes();
		$checks = array(
			'/jetonomy/v1/badges'                              => array( 'page', 'per_page' ),
			'/jetonomy/v1/conversations'                       => array( 'limit', 'offset' ),
			'/jetonomy/v1/conversations/(?P<id>\d+)/messages'  => array( 'before', 'limit' ),
			'/jetonomy/v1/analytics/top-spaces'                => array( 'limit' ),
			'/jetonomy/v1/analytics/top-contributors'          => array( 'limit' ),
			'/jetonomy/v1/analytics/diff-report'               => array( 'days' ),
		);
		foreach ( $checks as $route => $args ) {
			$this->assertArrayHasKey( $route, $routes, $route );
			$get = null;
			foreach ( $routes[ $route ] as $handler ) {
				if ( ! empty( $handler['methods']['GET'] ) ) {
					$get = $handler;
				}
			}
			$this->assertNotNull( $get, "GET handler for {$route}" );
			foreach ( $args as $arg ) {
				$cb = $get['args'][ $arg ]['sanitize_callback'];
				$this->assertSame( -5, call_user_func( $cb, '-5', new WP_REST_Request(), $arg ), "{$route} {$arg}" );
				$this->assertSame( 0, call_user_func( $cb, 'abc', new WP_REST_Request(), $arg ), "{$route} {$arg}" );
			}
		}
	}

	/**
	 * The handlers that now see negatives must still answer 200 with a real
	 * page (no negative SQL LIMIT).
	 */
	public function test_negative_limits_answer_a_default_page(): void {
		global $wpdb;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( 'jetonomy_view_analytics' );
		wp_set_current_user( $admin );

		foreach ( array( '/jetonomy/v1/conversations', '/jetonomy/v1/analytics/top-contributors', '/jetonomy/v1/analytics/top-spaces' ) as $route ) {
			foreach ( array( '-5', 'abc', '0' ) as $value ) {
				$request = new WP_REST_Request( 'GET', $route );
				$request->set_query_params( array( 'limit' => $value ) );
				$wpdb->last_error = '';
				$response         = $this->server->dispatch( $request );
				$this->assertSame( 200, $response->get_status(), "{$route} limit={$value}" );
				$this->assertSame( '', $wpdb->last_error, "{$route} limit={$value}" );
			}
		}
	}
}
