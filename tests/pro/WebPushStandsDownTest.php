<?php
/**
 * Web Push: an inbox that sends its own push for the event (BuddyNext) can ask Jetonomy Pro to stand down,
 * so the member gets one push, not one from each plugin.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class WebPushStandsDownTest extends WP_UnitTestCase {

	/**
	 * Notification ids the extension has collected to push, by push key.
	 */
	private function collected( \Jetonomy_Pro\Extensions\Web_Push\Extension $ext ): array {
		$pending = new \ReflectionProperty( $ext, 'pending' );
		$pending->setAccessible( true );
		return $pending->getValue( $ext );
	}

	public function test_a_no_from_the_filter_keeps_the_push_from_being_collected(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		$ext = new \Jetonomy_Pro\Extensions\Web_Push\Extension();
		$ext->on_notification_created( 11, 5, 'reply', 'post', 3, 'Someone replied', '/x' );
		$this->assertCount( 1, $this->collected( $ext ), 'By default the push is collected.' );

		$quiet = new \Jetonomy_Pro\Extensions\Web_Push\Extension();
		add_filter( 'jetonomy_pro_push_should_send', '__return_false' );
		$quiet->on_notification_created( 12, 5, 'reply', 'post', 3, 'Someone replied', '/x' );
		remove_filter( 'jetonomy_pro_push_should_send', '__return_false' );
		$this->assertSame( array(), $this->collected( $quiet ), 'A no means nothing is queued.' );
		remove_all_actions( 'shutdown' );
	}
}
