<?php
/**
 * The dormant Conversations screen (WPMediaVerse owns messages) is a hidden
 * admin page, which WordPress cannot title on its own: the browser tab read
 * " < Site". The page seeds its own title.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class ConversationsDormantTitleTest extends WP_UnitTestCase {

	public function test_dormant_page_has_a_title(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Extensions\Private_Messaging\Admin_Page' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$GLOBALS['title'] = null;

		\Jetonomy_Pro\Extensions\Private_Messaging\Admin_Page::register_dormant_notice();
		do_action( 'admin_menu' );
		do_action( 'load-' . get_plugin_page_hookname( 'jetonomy-pro-conversations', '' ) );

		$this->assertSame( 'Conversations', $GLOBALS['title'] );
	}
}
