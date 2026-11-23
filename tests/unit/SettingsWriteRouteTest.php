<?php
/**
 * GET/POST /jetonomy/v1/admin/settings and the one settings writer behind it.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use Jetonomy\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The route only writes registered keys, through the admin form's sanitizer,
 * for manage_options holders, and never echoes a secret.
 */
class SettingsWriteRouteTest extends WP_UnitTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_option(
			'jetonomy_settings',
			array(
				'base_slug'          => 'community',
				'accent_color'       => '#0073aa',
				'custom_css'         => '.a{color:red}',
				'captcha_provider'   => 'turnstile',
				'captcha_secret_key' => 'sekret-value',
				'seo_post_title'     => '{post_title} | {site_name}',
			)
		);
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		$_COOKIE = array();
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	private function post( array $settings, int $as ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/admin/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'settings' => $settings ) ) );
		return rest_do_request( $request );
	}

	public function test_a_subscriber_and_a_settings_delegate_are_refused(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame( 403, $this->post( array( 'accent_color' => '#111111' ), $sub )->get_status() );

		// jetonomy_manage_settings opens the form, not the API.
		$delegate = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $delegate )->add_cap( 'jetonomy_manage_settings' );
		$this->assertSame( 403, $this->post( array( 'accent_color' => '#111111' ), $delegate )->get_status() );

		$this->assertSame( '#0073aa', get_option( 'jetonomy_settings' )['accent_color'] );
	}

	public function test_an_admin_write_is_sanitized_and_neighbours_survive(): void {
		$response = $this->post(
			array(
				'accent_color'           => '#ABCDEF',
				'container_width_custom' => 99999,
				'seo_post_title'         => '<b>x</b> {post_title}',
			),
			$this->admin
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data   = $response->get_data();
		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( '#ABCDEF', $stored['accent_color'] );
		$this->assertSame( 2400, $stored['container_width_custom'], 'Out-of-range number is clamped, not stored raw.' );
		$this->assertSame( 'x {post_title}', $stored['seo_post_title'] );
		$this->assertSame( '#ABCDEF', $data['settings']['accent_color'] );
		$this->assertSame( 2400, $data['settings']['container_width_custom'] );
		// Same section, not sent: kept.
		$this->assertSame( '.a{color:red}', $stored['custom_css'] );
		$this->assertSame( 'turnstile', $stored['captcha_provider'] );
		$this->assertSame( 'sekret-value', $stored['captcha_secret_key'] );
	}

	public function test_an_unknown_key_or_a_form_marker_is_refused_and_nothing_is_written(): void {
		$before = get_option( 'jetonomy_settings' );
		foreach ( array( 'not_a_setting', 'role_caps_submitted', 'role_caps' ) as $key ) {
			$response = $this->post(
				array(
					'accent_color' => '#222222',
					$key           => 1,
				),
				$this->admin
			);
			$this->assertSame( 400, $response->get_status(), $key );
			$this->assertSame( 'jetonomy_unknown_setting', $response->get_data()['code'], $key );
		}
		$this->assertSame( $before, get_option( 'jetonomy_settings' ) );
	}

	public function test_a_value_outside_the_schema_is_refused(): void {
		$this->assertSame( 400, $this->post( array( 'captcha_provider' => 'evil' ), $this->admin )->get_status() );
		$this->assertSame( 400, $this->post( array( 'accent_color' => 'red;}body{display:none' ), $this->admin )->get_status() );
		$this->assertSame( 400, $this->post( array( 'posts_per_page' => 'lots' ), $this->admin )->get_status() );
		$this->assertSame( 'turnstile', get_option( 'jetonomy_settings' )['captcha_provider'] );
	}

	public function test_more_than_fifty_keys_is_refused(): void {
		$many = array();
		foreach ( array_slice( array_keys( Settings::schema() ), 0, Settings::MAX_KEYS + 1 ) as $key ) {
			$many[ $key ] = '';
		}
		$this->assertCount( Settings::MAX_KEYS + 1, $many );
		$this->assertSame( 400, $this->post( $many, $this->admin )->get_status() );
	}

	public function test_secrets_are_never_echoed_and_the_mask_keeps_the_stored_one(): void {
		wp_set_current_user( $this->admin );
		$get = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/admin/settings' ) );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( Settings::MASK, $get->get_data()['settings']['captcha_secret_key'] );
		$this->assertStringNotContainsString( 'sekret-value', (string) wp_json_encode( $get->get_data() ) );

		// A client that sends back what it read does not overwrite the secret.
		$this->post( array( 'captcha_secret_key' => Settings::MASK ), $this->admin );
		$this->assertSame( 'sekret-value', get_option( 'jetonomy_settings' )['captcha_secret_key'] );

		$post = $this->post( array( 'captcha_secret_key' => 'new-secret' ), $this->admin );
		$this->assertSame( Settings::MASK, $post->get_data()['settings']['captcha_secret_key'] );
		$this->assertSame( 'new-secret', get_option( 'jetonomy_settings' )['captcha_secret_key'] );
	}

	public function test_a_cookie_request_without_a_nonce_is_refused(): void {
		$_COOKIE = array( LOGGED_IN_COOKIE => 'x' );
		$response = $this->post( array( 'accent_color' => '#333333' ), $this->admin );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cookie_invalid_nonce', $response->get_data()['code'] );
		$this->assertSame( '#0073aa', get_option( 'jetonomy_settings' )['accent_color'] );
	}

	public function test_a_write_announces_the_changed_keys_and_its_source(): void {
		$seen = array();
		add_action(
			'jetonomy_settings_updated',
			static function ( array $changed, string $source ) use ( &$seen ): void {
				$seen[] = array( $changed, $source );
			},
			10,
			2
		);
		$this->post( array( 'accent_color' => '#444444' ), $this->admin );
		update_option( 'jetonomy_settings', array_merge( get_option( 'jetonomy_settings' ), array( 'custom_css' => '' ) ) );

		// The first Appearance write also stores that section's defaults, as the form does.
		$this->assertContains( 'accent_color', $seen[0][0] );
		$this->assertNotContains( 'seo_post_title', $seen[0][0], 'Another section is not rewritten.' );
		$this->assertSame( 'rest', $seen[0][1] );
		$this->assertSame( array( array( 'custom_css' ), 'form' ), $seen[1] );
	}

	/**
	 * Writing a section fills its untouched keys from the stored values, so each
	 * key's sanitizer must give back what it stored. Proven for every key the
	 * form can save, on values the form itself produced.
	 */
	public function test_every_stored_value_survives_a_write_of_itself(): void {
		wp_set_current_user( $this->admin );
		delete_option( 'jetonomy_settings' );
		$form = array(
			'base_slug'             => 'forum',
			'community_title'       => 'Our Place',
			'terms_url'             => 'https://example.com/terms',
			'posts_per_page'        => 25,
			'guest_read'            => '1',
			'front_page'            => '1',
			'trust_thresholds'      => array( 1 => array( 'posts' => 4 ) ),
			'trust_level_names'     => array( 1 => 'Newbie' ),
			'member_name_display'   => 'both',
			'rate_limits'           => array( 'posts' => 7 ),
			'reputation_points'     => array(),
			'email_from_name'       => 'Team',
			'email_from_email'      => 'team@example.com',
			'notification_defaults' => array( 'mention' => array( 'email' => '1' ) ),
			'accent_color'          => '#123456',
			'custom_css'            => '.x{color:blue}',
			'container_width'       => 'custom',
			'captcha_provider'      => 'recaptcha_v3',
			'captcha_secret_key'    => 'abc',
			'seo_post_title'        => '{post_title}',
			'seo_schema'            => '1',
			'seo_twitter_handle'    => '@team',
		);
		update_option( 'jetonomy_settings', Settings::sanitize( $form ) );
		$stored = get_option( 'jetonomy_settings' );

		foreach ( array_keys( Settings::schema() ) as $key ) {
			if ( ! array_key_exists( $key, $stored ) || ! empty( Settings::schema()[ $key ]['secret'] ) ) {
				continue;
			}
			$result = Settings::write( array( $key => $stored[ $key ] ), 'rest' );
			$this->assertIsArray( $result, $key . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->assertSame( $stored, get_option( 'jetonomy_settings' ), $key . ' changed something on a write of its own value.' );
		}
	}
}
