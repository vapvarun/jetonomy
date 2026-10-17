<?php
/**
 * User-profile .18/.19: with WPMediaVerse active, Pro messaging stands down,
 * and the profile still offers Message, wired to WPMediaVerse's public
 * `mvs-open-conversation` event. WPMediaVerse decides who may message whom;
 * a Jetonomy block either way also hides it.
 *
 * Runs in its own process: the WPMediaVerse stub makes is_dormant() true for
 * the rest of the process, which would switch messaging off for every other test.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\BlockedUser;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MessagingDormantProfileButtonTest extends WP_UnitTestCase {

	public function test_dormant_messaging_hands_the_profile_button_to_wpmediaverse(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Extensions\Private_Messaging\Extension' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();

		require_once __DIR__ . '/Fixtures/WPMediaVerseStub.php';
		$allowed  = true;
		$mvs_msgs = new class( $allowed ) {
			public bool $allowed;
			public function __construct( bool $allowed ) {
				$this->allowed = $allowed;
			}
			public function can_message( int $from, int $to ): array {
				return array( 'allowed' => $this->allowed );
			}
		};
		\WPMediaVerse\Core\Plugin::$services['messaging'] = $mvs_msgs;

		$ext = '\Jetonomy_Pro\Extensions\Private_Messaging\Extension';
		$this->assertTrue( $ext::is_dormant() );
		( new $ext() )->boot();

		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$alice = get_userdata( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		wp_set_current_user( $bob );
		$this->assertSame( '', \Jetonomy\member_message_url( $alice ), 'no Jetonomy messages link while dormant' );
		$this->assertSame( 'mvs-open-conversation', \Jetonomy\member_message_event( $alice ) );

		$mvs_msgs->allowed = false;
		$this->assertSame( '', \Jetonomy\member_message_event( $alice ), 'WPMediaVerse says no (its block, DM privacy)' );
		$mvs_msgs->allowed = true;

		BlockedUser::block( (int) $alice->ID, $bob );
		$this->assertSame( '', \Jetonomy\member_message_event( $alice ), 'a Jetonomy block either way hides it' );
		BlockedUser::unblock( (int) $alice->ID, $bob );

		unset( \WPMediaVerse\Core\Plugin::$services['messaging'] );
		$this->assertSame( '', \Jetonomy\member_message_event( $alice ), 'WPMediaVerse messaging switched off' );

		wp_set_current_user( 0 );
		\WPMediaVerse\Core\Plugin::$services['messaging'] = $mvs_msgs;
		$this->assertSame( '', \Jetonomy\member_message_event( $alice ), 'guests get no button' );
	}
}
