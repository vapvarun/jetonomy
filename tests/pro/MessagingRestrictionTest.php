<?php
/**
 * Private messaging: banned or silenced members cannot send through any path
 * (new conversation, the existing-thread reuse path, or a reply) and are told
 * why in Free's wording; a conversation the caller is not in answers exactly
 * like a missing one.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Restriction;
use Jetonomy\DB\Schema;

class MessagingRestrictionTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;
	private int $sender_id;
	private int $recipient_id;
	private int $outsider_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		$ext = new \Jetonomy_Pro\Extensions\Private_Messaging\Extension();
		$ext->activate();
		$ext->boot();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->sender_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->recipient_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		UserProfile::find_or_create( $this->sender_id );
		UserProfile::update_profile( $this->sender_id, array( 'trust_level' => 1 ) );

		$cat   = Category::create( array( 'name' => 'PM', 'slug' => 'pm-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'Shared', 'slug' => 'pm-shared-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		SpaceMember::add( $space, $this->sender_id, 'member' );
		SpaceMember::add( $space, $this->recipient_id, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function dispatch( string $method, string $route, array $params, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return $this->server->dispatch( $request );
	}

	private function start_thread(): int {
		$res = $this->dispatch( 'POST', '/jetonomy/v1/conversations', array( 'recipient_ids' => array( $this->recipient_id ), 'message' => 'Hello' ), $this->sender_id );
		$this->assertContains( $res->get_status(), array( 200, 201 ), wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['conversation_id'];
	}

	private function message_count( int $conversation_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_messages WHERE conversation_id = %d", $conversation_id ) );
	}

	public function test_silenced_member_cannot_append_through_the_reuse_path(): void {
		$conv = $this->start_thread();
		Restriction::ban( $this->sender_id, 'silence', $this->admin_id );

		$res = $this->dispatch( 'POST', '/jetonomy/v1/conversations', array( 'recipient_ids' => array( $this->recipient_id ), 'message' => 'Again' ), $this->sender_id );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $res->get_data()['code'] );
		$this->assertSame( 1, $this->message_count( $conv ) );
	}

	/** @covers-existing The REST auth layer already refuses a banned member. */
	public function test_banned_member_cannot_start_a_conversation(): void {
		Restriction::ban( $this->sender_id, 'global_ban', $this->admin_id );
		$res = $this->dispatch( 'POST', '/jetonomy/v1/conversations', array( 'recipient_ids' => array( $this->recipient_id ), 'message' => 'Hi' ), $this->sender_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_banned', $res->get_data()['code'] );
	}

	public function test_reply_uses_free_restriction_wording(): void {
		$conv = $this->start_thread();
		Restriction::ban( $this->sender_id, 'silence', $this->admin_id );

		$res      = $this->dispatch( 'POST', "/jetonomy/v1/conversations/{$conv}/messages", array( 'content' => 'Hi' ), $this->sender_id );
		$expected = \Jetonomy\Permissions\Permission_Engine::restriction_error( $this->sender_id, 'create_replies' );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( $expected->get_error_code(), $res->get_data()['code'] );
		$this->assertSame( $expected->get_error_message(), $res->get_data()['message'] );
	}

	/** @covers-existing The REST auth layer already refuses a banned member. */
	public function test_banned_member_cannot_reply(): void {
		$conv = $this->start_thread();
		Restriction::ban( $this->sender_id, 'global_ban', $this->admin_id );

		$res = $this->dispatch( 'POST', "/jetonomy/v1/conversations/{$conv}/messages", array( 'content' => 'Hi' ), $this->sender_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_banned', $res->get_data()['code'] );
		$this->assertSame( 1, $this->message_count( $conv ) );
	}

	public function test_conversation_the_caller_is_not_in_reads_like_missing(): void {
		$conv = $this->start_thread();
		$routes = array(
			array( 'GET', '/jetonomy/v1/conversations/%d', array() ),
			array( 'PATCH', '/jetonomy/v1/conversations/%d', array( 'is_muted' => true ) ),
			array( 'GET', '/jetonomy/v1/conversations/%d/messages', array() ),
			array( 'POST', '/jetonomy/v1/conversations/%d/messages', array( 'content' => 'Hi' ) ),
			array( 'POST', '/jetonomy/v1/conversations/%d/mute', array( 'muted' => true ) ),
			array( 'POST', '/jetonomy/v1/conversations/%d/archive', array() ),
			array( 'POST', '/jetonomy/v1/conversations/%d/leave', array() ),
			array( 'POST', '/jetonomy/v1/conversations/%d/block', array() ),
		);
		foreach ( $routes as list( $method, $route, $params ) ) {
			$hidden  = $this->dispatch( $method, sprintf( $route, $conv ), $params, $this->outsider_id );
			$missing = $this->dispatch( $method, sprintf( $route, 999999 ), $params, $this->outsider_id );
			$this->assertSame( 404, $hidden->get_status(), "{$method} {$route}" );
			$this->assertNotSame( 'rest_no_route', $hidden->get_data()['code'] ?? '' );
			$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'], "{$method} {$route}" );
			$this->assertSame( $missing->get_data()['message'], $hidden->get_data()['message'], "{$method} {$route}" );
		}
	}
}
