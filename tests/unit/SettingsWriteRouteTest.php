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
				'container_width_custom' => 1320,
				'seo_post_title'         => '<b>x</b> {post_title}',
			),
			$this->admin
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data   = $response->get_data();
		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( '#ABCDEF', $stored['accent_color'] );
		$this->assertSame( 1320, $stored['container_width_custom'] );
		$this->assertSame( 'x {post_title}', $stored['seo_post_title'] );
		$this->assertSame( '#ABCDEF', $data['settings']['accent_color'] );
		$this->assertSame( 1320, $data['settings']['container_width_custom'] );
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
		$response = $this->post( $many, $this->admin );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_settings_too_many', $response->get_data()['code'] );
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

		// The first Appearance write also stores that section's defaults; only the sent key changed.
		$this->assertSame( array( array( 'accent_color' ), 'rest' ), $seen[0] );
		$this->assertSame( array( array( 'custom_css' ), 'form' ), $seen[1] );
	}

	public function test_a_one_key_write_on_a_fresh_option_keeps_default_on_checkboxes_on(): void {
		delete_option( 'jetonomy_settings' );
		$seen = array();
		add_action(
			'jetonomy_settings_updated',
			static function ( array $changed ) use ( &$seen ): void {
				$seen[] = $changed;
			}
		);

		$this->assertSame( 200, $this->post( array( 'seo_twitter_handle' => 'abc' ), $this->admin )->get_status() );
		$stored = get_option( 'jetonomy_settings' );
		foreach ( array( 'seo_schema', 'seo_sitemap', 'seo_noindex_profiles', 'seo_noindex_search' ) as $key ) {
			$this->assertTrue( $stored[ $key ], $key . ' was turned off by a write that did not send it.' );
		}
		$this->assertSame( array( array( 'seo_twitter_handle' ) ), $seen );

		// Notification defaults keep the table's reading: web on, email per the notifier.
		$this->post( array( 'email_from_name' => 'Team' ), $this->admin );
		$notif = get_option( 'jetonomy_settings' )['notification_defaults'];
		$this->assertTrue( $notif['mention']['web'] );
		$this->assertSame( \Jetonomy\Notifications\Notifier::default_email( 'mention', array() ), $notif['mention']['email'] );
		$this->assertTrue( $notif['join_request_result']['email'] ?? true, 'Email-on-when-unset types stay on.' );
		$this->assertSame( array( 'email_from_name' ), $seen[1] );
	}

	/** @covers-existing The form's unchecked-box rule must survive the default fill in write(). */
	public function test_the_form_still_turns_an_unchecked_box_off(): void {
		update_option( 'jetonomy_settings', array_merge( get_option( 'jetonomy_settings' ), array( 'seo_schema' => true ) ) );
		// The SEO tab posts its fields; an unchecked box posts nothing.
		update_option( 'jetonomy_settings', Settings::sanitize( array( 'seo_post_title' => '{post_title}' ) ) );
		$this->assertFalse( get_option( 'jetonomy_settings' )['seo_schema'] );
	}

	public function test_a_write_waits_for_the_lock_and_answers_503_when_it_stays_taken(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->assertTrue( \WP_Upgrader::create_lock( 'jetonomy_settings', 60 ) );
		$response = $this->post( array( 'accent_color' => '#555555' ), $this->admin );
		\WP_Upgrader::release_lock( 'jetonomy_settings' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'jetonomy_settings_busy', $response->get_data()['code'] );
		$this->assertSame( '#0073aa', get_option( 'jetonomy_settings' )['accent_color'] );

		// The lock is released after every write, so writes in a row all land.
		$this->assertSame( 200, $this->post( array( 'accent_color' => '#555555' ), $this->admin )->get_status() );
		$this->assertSame( 200, $this->post( array( 'custom_css' => '.b{}' ), $this->admin )->get_status() );
		$this->assertFalse( get_option( 'jetonomy_settings.lock' ) );
	}

	public function test_a_write_reads_what_another_request_saved_meanwhile(): void {
		get_option( 'jetonomy_settings' ); // Primes this request's cache.
		// Another request saves directly in the table, behind this request's cache.
		global $wpdb;
		$other                 = get_option( 'jetonomy_settings' );
		$other['custom_css']   = '.other{}';
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $other ) ), array( 'option_name' => 'jetonomy_settings' ) );

		$this->post( array( 'accent_color' => '#666666' ), $this->admin );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'jetonomy_settings', 'options' );
		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( '.other{}', $stored['custom_css'], 'The other request\'s change was lost.' );
		$this->assertSame( '#666666', $stored['accent_color'] );
	}

	public function test_a_refused_value_comes_back_as_a_notice_not_as_saved(): void {
		$data = $this->post( array( 'trust_thresholds' => array( 9 => array( 'posts' => 5 ) ) ), $this->admin )->get_data();
		$this->assertSame( 'jetonomy_setting_not_stored', $data['notices'][0]['code'] ?? '' );
		$data = $this->post( array( 'terms_url' => 'javascript:alert(1)' ), $this->admin )->get_data();
		$this->assertSame( array( 'jetonomy_invalid_terms_url' ), array_column( $data['notices'], 'code' ), 'One notice per refusal.' );
		$data = $this->post( array( 'seo_twitter_handle' => 'abc' ), $this->admin )->get_data();
		$this->assertSame( array(), $data['notices'], 'A cleaned value that was stored is not a refusal.' );
	}

	public function test_sizes_and_ranges_are_capped_and_a_large_option_is_not_autoloaded(): void {
		$before = get_option( 'jetonomy_settings' );
		foreach ( array(
			array( 'custom_css' => str_repeat( 'a', 65536 ) ),
			array( 'community_title' => str_repeat( 'a', 1001 ) ),
			array( 'terms_url' => 'https://example.com/' . str_repeat( 'a', 2048 ) ),
			array( 'rate_limits' => array( 'posts' => 1e15 ) ),
			array( 'container_width_custom' => 99999 ),
			array( 'trust_thresholds' => array( 1 => array( 'posts' => -1 ) ) ),
			array( 'captcha_secret_key' => '*******' ), // A mangled mask is not a secret.
		) as $patch ) {
			$this->assertSame( 400, $this->post( $patch, $this->admin )->get_status(), (string) key( $patch ) );
		}
		$this->assertSame( $before, get_option( 'jetonomy_settings' ) );

		global $wpdb;
		$autoload = static fn(): string => (string) $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'jetonomy_settings'" );
		// The form has no cap on CSS; whatever the size, the flag follows it.
		update_option( 'jetonomy_settings', array_merge( $before, array( 'custom_css' => str_repeat( 'a', 200000 ) ) ) );
		$this->assertContains( $autoload(), array( 'off', 'no' ), 'A 200 KB option is autoloaded.' );
		update_option( 'jetonomy_settings', $before );
		$this->assertContains( $autoload(), array( 'on', 'yes' ), 'The option stayed off autoload after shrinking.' );
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

	public function test_a_decimal_range_error_names_the_real_limits(): void {
		wp_set_current_user( $this->admin );
		foreach ( array( 1.5, 0.05 ) as $value ) {
			$result = Settings::write( array( 'captcha_score_threshold' => $value ), 'rest' );
			$this->assertWPError( $result );
			$this->assertStringContainsString( 'between 0.1 and 0.9', $result->get_error_message() );
		}
		$this->assertIsArray( Settings::write( array( 'captcha_score_threshold' => 0.7 ), 'rest' ) );
	}

	/**
	 * Every boolean setting, read from the schema so a new one is covered.
	 *
	 * @return string[]
	 */
	private function boolean_keys(): array {
		return array_keys( array_filter( Settings::schema(), static fn( array $d ): bool => 'boolean' === $d['type'] ) );
	}

	public function test_the_string_false_stores_false_on_every_boolean_key(): void {
		$keys = $this->boolean_keys();
		$this->assertContains( 'moderators_can_ban', $keys );
		$off = array( 'false', '0', 0, false );
		$on  = array( 'true', '1', 1, true );
		foreach ( $keys as $key ) {
			foreach ( $off as $value ) {
				$this->assertSame( 200, $this->post( array( $key => true ), $this->admin )->get_status(), $key );
				$this->assertSame( 200, $this->post( array( $key => $value ), $this->admin )->get_status(), $key . ' ' . wp_json_encode( $value ) );
				$this->assertFalse( get_option( 'jetonomy_settings' )[ $key ], $key . ' ' . wp_json_encode( $value ) );
			}
			foreach ( $on as $value ) {
				$this->post( array( $key => false ), $this->admin );
				$this->assertSame( 200, $this->post( array( $key => $value ), $this->admin )->get_status(), $key . ' ' . wp_json_encode( $value ) );
				$this->assertTrue( get_option( 'jetonomy_settings' )[ $key ], $key . ' ' . wp_json_encode( $value ) );
			}
		}
	}

	/** @covers-existing null, arrays and other strings were already refused; pinned with the fix. */
	public function test_anything_else_on_a_boolean_key_is_refused_and_keeps_the_stored_value(): void {
		foreach ( $this->boolean_keys() as $key ) {
			$this->post( array( $key => true ), $this->admin );
			foreach ( array( null, array(), array( 'x' ), 'no', 'yes', 'off', '2', 2 ) as $value ) {
				$response = $this->post( array( $key => $value ), $this->admin );
				$this->assertSame( 400, $response->get_status(), $key . ' ' . wp_json_encode( $value ) );
				$this->assertTrue( get_option( 'jetonomy_settings' )[ $key ], $key . ' ' . wp_json_encode( $value ) );
			}
		}
	}

	public function test_a_nested_string_false_stores_false(): void {
		$type    = array_key_first( array_filter( \Jetonomy\Admin\Admin::email_types(), static fn( array $t ): bool => $t['toggle'] ) );
		$payload = array(
			$type => array(
				'web'   => 'false',
				'email' => 'true',
			),
		);
		$this->assertSame( 200, $this->post( array( 'notification_defaults' => $payload ), $this->admin )->get_status() );
		$this->assertSame(
			array(
				'web'   => false,
				'email' => true,
			),
			get_option( 'jetonomy_settings' )['notification_defaults'][ $type ]
		);
	}

	public function test_reputation_points_refuse_anything_but_a_whole_number(): void {
		$this->post( array( 'reputation_points' => array( 'post_upvoted' => 7 ) ), $this->admin );
		foreach ( array( 'false', 'true', 'yes', 'abc', '0x1A', '5abc', '1.5', false, true, null ) as $bad ) {
			$response = $this->post( array( 'reputation_points' => array( 'post_upvoted' => $bad ) ), $this->admin );
			$this->assertSame( 400, $response->get_status(), wp_json_encode( $bad ) );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], wp_json_encode( $bad ) );
			$this->assertSame( 7, get_option( 'jetonomy_settings' )['reputation_points']['post_upvoted'], wp_json_encode( $bad ) );
		}
	}

	/** @covers-existing whole numbers and the empty reset already worked; pinned with the fix. */
	public function test_reputation_points_take_numbers_and_empty_means_the_default(): void {
		foreach ( array( '-3' => -3, '12' => 12 ) as $sent => $stored ) {
			$this->assertSame( 200, $this->post( array( 'reputation_points' => array( 'post_upvoted' => (string) $sent ) ), $this->admin )->get_status() );
			$this->assertSame( $stored, get_option( 'jetonomy_settings' )['reputation_points']['post_upvoted'] );
		}
		$this->assertSame( 200, $this->post( array( 'reputation_points' => array( 'post_upvoted' => '' ) ), $this->admin )->get_status() );
		$this->assertArrayNotHasKey( 'post_upvoted', get_option( 'jetonomy_settings' )['reputation_points'] );
	}

	public function test_a_fresh_site_reads_the_defaults_it_uses_and_can_post_them_back(): void {
		delete_option( 'jetonomy_settings' );
		wp_set_current_user( $this->admin );
		$json = (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/admin/settings' ) )->get_data() );
		$read = json_decode( $json, true )['settings'];
		$this->assertSame( array(), array_keys( array_filter( $read, 'is_null' ) ), 'no key reads as null' );
		$this->assertSame( \Jetonomy\Trust\Reputation::action_points_defaults(), $read['reputation_points'], 'the points each action gives' );
		$this->assert_maps_are_objects( $json );
		$this->assertSame( \Jetonomy\Permissions\Rate_Limiter::defaults(), $read['rate_limits'] );
		$this->assertSame( \Jetonomy\Trust\Trust_Levels::defaults()[2]['posts'], $read['trust_thresholds'][2]['posts'] );
		$this->assertFalse( $read['moderators_can_ban'] );
		$this->assertSame( 'display_name', $read['member_name_display'] );

		$back = array_intersect_key( $read, array_flip( array( 'trust_thresholds', 'trust_level_names', 'moderators_can_ban', 'member_name_display', 'rate_limits', 'reputation_points' ) ) );
		$this->assertSame( 200, $this->post( $back, $this->admin )->get_status(), 'the read body posts back' );

		// Saved with one override: still the full map, still objects.
		$this->post( array( 'reputation_points' => array( 'post_upvoted' => 7 ) ), $this->admin );
		$json = (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/admin/settings' ) )->get_data() );
		$this->assertSame( 7, json_decode( $json, true )['settings']['reputation_points']['post_upvoted'] );
		$this->assertSame( count( \Jetonomy\Trust\Reputation::action_points_defaults() ), count( json_decode( $json, true )['settings']['reputation_points'] ) );
		$this->assert_maps_are_objects( $json );
	}

	/**
	 * Every object-typed setting serialises as a JSON object, never [].
	 *
	 * @param string $json GET /admin/settings body.
	 */
	private function assert_maps_are_objects( string $json ): void {
		$settings = json_decode( $json, false )->settings;
		foreach ( \Jetonomy\Settings::schema() as $key => $def ) {
			if ( 'object' === $def['type'] ) {
				$this->assertIsObject( $settings->{$key}, "{$key} is a JSON object" );
			}
		}
	}

	public function test_an_out_of_range_form_number_is_saved_as_the_nearest_value_with_a_notice(): void {
		$notices = new \ReflectionProperty( Settings::class, 'notices' );
		$notices->setValue( null, array() );
		wp_set_current_user( $this->admin );
		$clean = Settings::sanitize(
			array(
				'email_from_name'             => '',
				'verification_reminder_hours' => '-5',
			)
		);
		$this->assertSame( 0, $clean['verification_reminder_hours'] );
		$this->assertContains( 'jetonomy_invalid_verification_reminder_hours', array_column( $notices->getValue(), 'code' ) );
	}
}
