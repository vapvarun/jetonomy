<?php
/**
 * Web Push and Reply by Email had their own "Enable" box on top of the
 * Extensions toggle. 2.0.7 keeps one switch: an unticked box becomes the
 * extension switched off, and Reply by Email runs once it can read replies.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Web_Push\Extension as Web_Push;
use Jetonomy_Pro\Extensions\Reply_By_Email\Extension as Reply_By_Email;

class OneSwitchPerExtensionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Reply_By_Email::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
	}

	private function is_on( string $id ): bool {
		return in_array( $id, (array) get_option( 'jetonomy_pro_extensions', array() ), true );
	}

	public function test_an_unticked_box_switches_the_extension_off(): void {
		update_option( 'jetonomy_pro_extensions', array( 'web-push', 'reply-by-email', 'polls' ) );
		update_option( 'jetonomy_pro_web_push', array( 'enabled' => false, 'notification_title' => 'Hi' ) );
		update_option( 'jetonomy_pro_reply_by_email', array( 'enabled' => false, 'method' => 'webhook', 'webhook_secret' => 's' ) );

		( new Web_Push() )->activate();
		( new Reply_By_Email() )->activate();

		$this->assertFalse( $this->is_on( 'web-push' ) );
		$this->assertFalse( $this->is_on( 'reply-by-email' ) );
		$this->assertTrue( $this->is_on( 'polls' ), 'Other extensions are untouched.' );
		$this->assertSame( array( 'notification_title' => 'Hi' ), get_option( 'jetonomy_pro_web_push' ) );
		$this->assertArrayNotHasKey( 'enabled', (array) get_option( 'jetonomy_pro_reply_by_email' ) );
	}

	public function test_a_ticked_box_keeps_the_extension_on(): void {
		update_option( 'jetonomy_pro_extensions', array( 'web-push' ) );
		update_option( 'jetonomy_pro_web_push', array( 'enabled' => true ) );

		( new Web_Push() )->activate();

		$this->assertTrue( $this->is_on( 'web-push' ) );
		$this->assertArrayNotHasKey( 'enabled', (array) get_option( 'jetonomy_pro_web_push' ) );
	}

	public function test_reply_by_email_is_ready_once_it_can_read_replies(): void {
		$this->assertSame( array( 'imap_host', 'imap_user', 'imap_pass' ), Reply_By_Email::missing_setup( array( 'method' => 'imap' ) ) );
		$this->assertSame( array( 'imap_pass' ), Reply_By_Email::missing_setup( array( 'method' => 'imap', 'imap_host' => 'imap.example.com', 'imap_user' => 'u', 'imap_pass' => ' ' ) ) );
		$this->assertTrue( Reply_By_Email::is_ready( array( 'method' => 'imap', 'imap_host' => 'imap.example.com', 'imap_user' => 'u', 'imap_pass' => 'p' ) ) );
		$this->assertTrue( Reply_By_Email::is_ready( array( 'method' => 'webhook' ) ), 'The webhook method needs no mailbox.' );
	}
}
