<?php
/**
 * The Extensions toggle is the only Email Digest switch. 2.0.7 retired the
 * tab's own "Enable Digests" box: a site that had it unticked keeps digests
 * off through the extension toggle, and an owner switching the extension on
 * is never switched straight back off.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Email_Digest\Extension;

class EmailDigestOneSwitchTest extends WP_UnitTestCase {

	private const SETTINGS = 'jetonomy_pro_digest_settings';
	private const ENABLED  = 'jetonomy_pro_extensions';

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Extension::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
	}

	private function is_on(): bool {
		return in_array( 'email-digest', (array) get_option( self::ENABLED, array() ), true );
	}

	public function test_an_unticked_box_becomes_the_extension_switched_off(): void {
		update_option( self::ENABLED, array( 'email-digest', 'polls' ) );
		update_option( self::SETTINGS, array( 'enabled' => false, 'default_frequency' => 'daily' ) );

		( new Extension() )->activate();

		$this->assertFalse( $this->is_on(), 'Digests stay off.' );
		$this->assertContains( 'polls', (array) get_option( self::ENABLED ), 'Other extensions are untouched.' );
		$this->assertSame( array( 'default_frequency' => 'daily' ), get_option( self::SETTINGS ), 'The retired key is gone; the rest is kept.' );
	}

	public function test_a_ticked_box_keeps_digests_on(): void {
		update_option( self::ENABLED, array( 'email-digest' ) );
		update_option( self::SETTINGS, array( 'enabled' => true, 'default_frequency' => 'weekly' ) );

		( new Extension() )->activate();

		$this->assertTrue( $this->is_on() );
		$this->assertArrayNotHasKey( 'enabled', (array) get_option( self::SETTINGS ) );
	}

	public function test_switching_the_extension_on_is_not_undone(): void {
		update_option( self::ENABLED, array() );
		update_option( self::SETTINGS, array( 'enabled' => false ) );

		// The toggle handler: activate(), then save the list with the extension in it.
		( new Extension() )->activate();
		update_option( self::ENABLED, array( 'email-digest' ) );
		( new Extension() )->activate(); // A later version change.

		$this->assertTrue( $this->is_on() );
	}
}
