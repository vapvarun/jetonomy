<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Feed;

/**
 * Two "saved but broken" inputs from QA on 2.0.7: a pasted title with control
 * characters made the space feed invalid XML, and an address setting stored
 * "not a url" as http://not%20a%20url.
 */
class FeedAndUrlInputTest extends WP_UnitTestCase {

	public function test_feed_text_is_always_valid_xml(): void {
		$text = "Tip\x08pasted\x0Bfrom Word\x0Cpage \x00 caf\xC3\xA9 \xF0\x9F\x98\x80 &hellip; <b>";
		$out  = Feed::xml( $text );

		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( '<t>' . $out . '</t>' );
		libxml_use_internal_errors( $prev );

		$this->assertNotFalse( $doc, 'The node parses as XML.' );
		$this->assertSame( "Tippasted from Word page  café 😀 … <b>", (string) $doc, 'Vertical tab and form feed read as spaces; nothing visible is lost.' );
	}

	public function test_an_address_setting_refuses_what_is_not_an_address(): void {
		$this->assertNull( \Jetonomy\http_url( 'not a url' ) );
		$this->assertNull( \Jetonomy\http_url( 'javascript:alert(1)' ) );
		$this->assertNull( \Jetonomy\http_url( 'ftp://example.com/x.png' ) );
		$this->assertNull( \Jetonomy\http_url( '//example.com/x.png' ) );
		$this->assertSame( '', \Jetonomy\http_url( '  ' ), 'Empty clears the setting.' );
		$this->assertSame( 'https://example.com/logo.png', \Jetonomy\http_url( ' https://example.com/logo.png ' ) );
		$this->assertSame( 'http://localhost:11434', \Jetonomy\http_url( 'http://localhost:11434' ) );
		$this->assertSame( '/wp-content/uploads/logo.png', \Jetonomy\http_url( '/wp-content/uploads/logo.png' ) );
	}

	public function test_the_settings_save_keeps_the_old_address_and_says_why(): void {
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset between tests.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'jetonomy_settings', array( 'email_logo_url' => 'https://example.com/old.png' ) );

		$clean = ( new \Jetonomy\Admin\Admin() )->sanitize_settings(
			array(
				'email_from_name' => 'Team',
				'email_logo_url'  => 'not a url',
			)
		);

		$this->assertSame( 'https://example.com/old.png', $clean['email_logo_url'] );
		$codes = wp_list_pluck( get_settings_errors( 'jetonomy_settings' ), 'code' );
		$this->assertContains( 'jetonomy_invalid_email_logo_url', $codes );
	}
}
