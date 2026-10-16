<?php
/**
 * A blank digest sender follows the Email tab's sender.
 *
 * Activation used to store the site name and admin email as the digest's
 * From fields, which froze the sender: changing the Email tab later never
 * reached digests. Blank (and that old snapshot) now means "use the Email
 * tab's sender"; a value the owner typed still wins.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Email_Digest\Extension;

class EmailDigestSenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Extension::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		update_option(
			'jetonomy_settings',
			array_merge(
				(array) get_option( 'jetonomy_settings', array() ),
				array(
					'email_from_name'  => 'Shared Sender',
					'email_from_email' => 'shared@example.org',
				)
			)
		);
	}

	private function sent_from( array $digest_settings ): string {
		update_option( 'jetonomy_pro_digest_settings', $digest_settings );
		reset_phpmailer_instance();
		$ref = new \ReflectionMethod( Extension::class, 'send_email' );
		$ref->invoke( new Extension(), 'member@example.org', 'Digest', '<p>Hi</p>', 'Hi' );
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer->FromName . ' <' . $mailer->From . '>';
	}

	public function test_activation_snapshot_and_blank_use_the_email_tab_sender(): void {
		$snapshot = array(
			'from_name'  => get_bloginfo( 'name' ),
			'from_email' => get_option( 'admin_email' ),
		);
		$this->assertSame( 'Shared Sender <shared@example.org>', $this->sent_from( $snapshot ) );
		$this->assertSame(
			'Shared Sender <shared@example.org>',
			$this->sent_from(
				array(
					'from_name'  => '',
					'from_email' => '',
				)
			)
		);
	}

	/**
	 * @covers-existing
	 */
	public function test_a_typed_digest_sender_still_wins(): void {
		$this->assertSame(
			'Digest Desk <digest@example.org>',
			$this->sent_from(
				array(
					'from_name'  => 'Digest Desk',
					'from_email' => 'digest@example.org',
				)
			)
		);
	}
}
