<?php
/**
 * A blank accent colour must never reach a reader.
 *
 * The Appearance tab used to save an empty field as an empty string, and every email, GET /app/config and the
 * settings GET then used it as-is: `background:;` renders white on white (the announcement preview, the sign-up
 * Confirm button). The writer saves the default instead, and every reader resolves through Settings::accent_color().
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Settings::accent_color
 */
class BlankAccentColorTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPTION, array( 'accent_color' => '' ) );
	}

	public function test_the_resolver_answers_the_default_for_a_blank_or_invalid_value_and_keeps_a_real_one(): void {
		$this->assertSame( '#0073aa', Settings::accent_color() );
		$this->assertSame( '#0073aa', Settings::accent_color( array( 'accent_color' => 'not-a-colour' ) ) );
		$this->assertSame( '#0073aa', Settings::accent_color( array() ) );
		$this->assertSame( '#10b981', Settings::accent_color( array( 'accent_color' => '#10b981' ) ) );
	}

	public function test_saving_a_blank_accent_stores_the_default(): void {
		$clean = Settings::sanitize( array( 'accent_color' => '' ) );
		$this->assertSame( '#0073aa', $clean['accent_color'] );
		$clean = Settings::sanitize( array( 'accent_color' => 'red' ) );
		$this->assertSame( '#0073aa', $clean['accent_color'], 'An invalid colour is not stored either.' );
	}

	public function test_every_email_carries_a_real_accent_not_an_empty_background(): void {
		$user = $this->factory()->user->create_and_get();
		$html = \Jetonomy\Notifications\Notifier::render_email_template( 'reply', 'Someone replied', $user );

		$this->assertStringContainsString( '#0073aa', $html, 'The default accent is used.' );
		$this->assertDoesNotMatchRegularExpression( '/background(-color)?\s*:\s*[;"\']/', $html, 'No empty background declaration.' );
	}

	public function test_the_settings_get_and_the_app_config_report_the_default(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( '#0073aa', Settings::read( array( 'accent_color' ) )['accent_color'], 'A GET body can be posted back.' );

		do_action( 'rest_api_init' );
		$config = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/app/config' ) )->get_data();
		$this->assertSame( '#0073aa', $config['branding']['accent_color'] ?? $config['accent_color'] ?? null );
	}

	public function test_the_settings_route_still_refuses_an_empty_accent(): void {
		$schema = Settings::schema();
		$this->assertSame( '^#([0-9a-fA-F]{3}){1,2}$', $schema['accent_color']['pattern'], 'The REST pattern is not loosened.' );
	}
}
