<?php
/**
 * Opening a conversation page reads it before the community header renders.
 *
 * The header's Messages badge (and the notification bell) render before the
 * conversation view, so marking read inside the view left both one page
 * behind ("Messages 1" on the thread you just opened). The read now happens
 * on template_redirect, ahead of any output.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;

class MessagingOpenMarksReadTest extends WP_UnitTestCase {

	private \Jetonomy_Pro\Extensions\Private_Messaging\Extension $ext;
	private int $sender_id;
	private int $recipient_id;
	private int $outsider_id;
	private int $conversation_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		update_option( 'jetonomy_pro_extensions', array( 'private-messaging' ) );
		$this->ext = new \Jetonomy_Pro\Extensions\Private_Messaging\Extension();
		$this->ext->activate();
		$this->ext->boot();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Admin sender bypasses the trust gate; recipient and outsider are members.
		$this->sender_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->recipient_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->sender_id );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/conversations' );
		$request->set_body_params(
			array(
				'recipient_ids' => array( $this->recipient_id ),
				'message'       => 'Hello there',
			)
		);
		$response              = $wp_rest_server->dispatch( $request );
		$this->conversation_id = (int) ( $response->get_data()['conversation_id'] ?? 0 );
		$this->assertGreaterThan( 0, $this->conversation_id, 'Fixture conversation was not created.' );
	}

	public function tear_down(): void {
		global $wp_rest_server, $wp_query;
		$wp_rest_server = null;
		$wp_query->set( 'jetonomy_route', '' );
		$wp_query->set( 'jetonomy_slug', '' );
		parent::tear_down();
	}

	private function open_as( int $user_id ): void {
		global $wp_query;
		wp_set_current_user( $user_id );
		$wp_query->set( 'jetonomy_route', 'conversation' );
		$wp_query->set( 'jetonomy_slug', (string) $this->conversation_id );
		$this->ext->mark_read_on_open();
	}

	public function test_read_is_hooked_before_the_page_renders(): void {
		$priority = has_action( 'template_redirect', array( $this->ext, 'mark_read_on_open' ) );
		$this->assertIsInt( $priority, 'Opening a thread must mark it read on template_redirect, before the header renders.' );
	}

	public function test_opening_the_thread_clears_the_header_count(): void {
		wp_set_current_user( $this->recipient_id );
		$this->assertSame( 1, \Jetonomy_Pro\Extensions\Private_Messaging\Extension::viewer_unread_count() );

		$this->open_as( $this->recipient_id );

		$this->assertSame( 0, \Jetonomy_Pro\Extensions\Private_Messaging\Extension::viewer_unread_count() );
	}

	public function test_a_non_participant_opening_the_url_changes_nothing(): void {
		$this->open_as( $this->outsider_id );

		wp_set_current_user( $this->recipient_id );
		$this->assertSame( 1, \Jetonomy_Pro\Extensions\Private_Messaging\Extension::viewer_unread_count() );
	}
}
