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
		foreach ( array( 'accent_color', 'logo_url', 'login_bg_url', 'terms_url', 'privacy_url', 'pro_active', 'features', 'attachments' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		foreach ( array( 'messaging', 'reactions', 'polls', 'badges', 'custom_fields', 'web_push', 'native_push', 'anonymous', 'attachments', 'blocking' ) as $flag ) {
			$this->assertArrayHasKey( $flag, $data['features'] );
		}
		// blocking is a FREE capability with no admin toggle — always true.
		$this->assertTrue( $data['features']['blocking'] );
	}

	/**
	 * 1.7.1: terms_url/privacy_url always come back as strings ('' when
	 * unconfigured) so the app's EULA screen can do a simple truthiness
	 * check without null-guarding.
	 */
	public function test_terms_and_privacy_url_default_to_empty_string(): void {
		update_option( 'jetonomy_settings', array() );

		$res  = $this->server->dispatch( new WP_REST_Request( 'GET', $this->route ) );
		$data = $res->get_data();

		$this->assertSame( '', $data['terms_url'] );
		$this->assertSame( '', $data['privacy_url'] );
	}

	/**
	 * terms_url has no core fallback; privacy_url falls back to WordPress'
	 * own Privacy Policy page when the admin hasn't set one explicitly.
	 */
	public function test_terms_url_configured_and_privacy_url_falls_back_to_core(): void {
		update_option( 'jetonomy_settings', array( 'terms_url' => 'https://example.com/terms/' ) );

		$privacy_page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'wp_page_for_privacy_policy', $privacy_page_id );

		$res  = $this->server->dispatch( new WP_REST_Request( 'GET', $this->route ) );
		$data = $res->get_data();

		$this->assertSame( 'https://example.com/terms/', $data['terms_url'] );
		$this->assertSame( get_privacy_policy_url(), $data['privacy_url'] );
		$this->assertNotSame( '', $data['privacy_url'] );
	}

	/**
	 * An explicit privacy_url setting always wins over the core fallback.
	 */
	public function test_privacy_url_setting_overrides_core_fallback(): void {
		update_option( 'jetonomy_settings', array( 'privacy_url' => 'https://example.com/privacy/' ) );

		$privacy_page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'wp_page_for_privacy_policy', $privacy_page_id );

		$res  = $this->server->dispatch( new WP_REST_Request( 'GET', $this->route ) );
		$data = $res->get_data();

		$this->assertSame( 'https://example.com/privacy/', $data['privacy_url'] );
	}

	/**
	 * attachments/anonymous are ordinary extension IDs; absent from the
	 * enabled-extensions option, so both default to false in free/no-Pro.
	 * The attachments{} object is ALWAYS one consistent shape.
	 */
	public function test_attachments_object_present_and_disabled_by_default(): void {
		$res  = $this->server->dispatch( new WP_REST_Request( 'GET', $this->route ) );
		$data = $res->get_data();

		$this->assertFalse( $data['features']['attachments'] );
		$this->assertFalse( $data['features']['anonymous'] );
		$this->assertArrayHasKey( 'attachments', $data );
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
