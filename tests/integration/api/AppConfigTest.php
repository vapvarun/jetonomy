<?php
/**
 * Integration test: GET /jetonomy/v1/app/config (1.6.0 mobile API).
 *
 * The mobile app reads this BEFORE login to theme its splash / sign-in
 * screens and to hide UI for extensions the site hasn't enabled. It must be
 * publicly readable, always expose the documented keys, and honour the
 * jetonomy_app_config override filter.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;

class AppConfigTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private string $route = '/jetonomy/v1/app/config';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'jetonomy_app_config' );
		parent::tear_down();
	}

	public function test_serves_app_config_publicly_with_a_features_block(): void {
		wp_set_current_user( 0 );

		$res  = $this->server->dispatch( new WP_REST_Request( 'GET', $this->route ) );
		$data = $res->get_data();

		$this->assertSame( 200, $res->get_status() );
		foreach ( array( 'accent_color', 'logo_url', 'login_bg_url', 'pro_active', 'features' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		foreach ( array( 'messaging', 'reactions', 'polls', 'badges', 'custom_fields', 'web_push', 'native_push' ) as $flag ) {
			$this->assertArrayHasKey( $flag, $data['features'] );
		}
	}

	public function test_filter_can_override_accent_color(): void {
		add_filter(
			'jetonomy_app_config',
			static function ( $d ) {
				$d['accent_color'] = '#000000';
				return $d;
			}
		);

		$res = $this->server->dispatch( new WP_REST_Request( 'GET', $this->route ) );
		$this->assertSame( '#000000', $res->get_data()['accent_color'] );
	}
}
