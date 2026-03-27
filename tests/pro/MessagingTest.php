<?php
/**
 * Integration tests for the Private Messaging Pro extension.
 *
 * All tests exercise the REST endpoints defined by
 * Jetonomy_Pro\Extensions\Private_Messaging\Extension via rest_do_request()
 * so the full permission-callback / validation / DB stack is exercised.
 *
 * The entire class is skipped when Jetonomy Pro is not active, so these
 * tests are safely included in the default suite and will simply report
 * "skipped" in CI environments that only have the free plugin.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

class MessagingTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int Admin user who can always send messages (manage_options bypass). */
	private int $admin_id;

	/** @var int Subscriber with trust_level >= 1 set explicitly. */
	private int $sender_id;

	/** @var int Subscriber who receives the conversation. */
	private int $recipient_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			return; // Individual tests will call markTestSkipped().
		}
	}

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — messaging tests skipped.' );
		}

		Schema::create_tables();

		// Enable the extension and fake a valid lifetime license so boot() runs.
		update_option( 'jetonomy_pro_extensions', [ 'private-messaging', 'reactions', 'polls', 'analytics' ] );
		update_option( 'jetonomy_pro_license', [
			'key'        => 'test-key',
			'status'     => 'valid',
			'expires'    => 'lifetime',
			'tier'       => 'lifetime',
			'item_name'  => 'Jetonomy Pro',
			'checked_at' => current_time( 'mysql', true ),
		] );

		// Ensure the Pro messaging tables exist and boot the extension so
		// REST routes are registered before rest_api_init fires.
		if ( class_exists( 'Jetonomy_Pro\Extensions\Private_Messaging\Extension' ) ) {
			$ext = new \Jetonomy_Pro\Extensions\Private_Messaging\Extension();
			$ext->activate();
			$ext->boot();
		}

		// Bootstrap REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create users.
		$this->admin_id     = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->sender_id    = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->recipient_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		// Grant the sender trust_level 1 so they pass the messaging gate.
		global $wpdb;
		$profiles_table = $wpdb->prefix . 'jt_user_profiles';
		// Ensure profile rows exist then set trust level.
		UserProfile::find_or_create( $this->sender_id );
		UserProfile::find_or_create( $this->recipient_id );
		$wpdb->update( $profiles_table, [ 'trust_level' => 1 ], [ 'user_id' => $this->sender_id ] );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a REST request and return the response.
	 *
	 * @param string   $method  HTTP method.
	 * @param string   $route   Full route including namespace, e.g. '/jetonomy/v1/conversations'.
	 * @param array    $params  Body params for POST/PATCH, query params for GET.
	 * @param int|null $user_id User to authenticate as, or null for guest.
	 */
	private function do_request( string $method, string $route, array $params = [], ?int $user_id = null ): \WP_REST_Response {
		wp_set_current_user( $user_id ?? 0 );

		$request = new WP_REST_Request( $method, $route );

		if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
			$request->set_body_params( $params );
		} else {
			foreach ( $params as $k => $v ) {
				$request->set_param( $k, $v );
			}
		}

		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Creating a conversation with a valid recipient must return 201 and
	 * include a conversation_id in the response body.
	 */
	public function test_create_conversation_returns_201_with_conversation_id(): void {
		$response = $this->do_request(
			'POST',
			'/jetonomy/v1/conversations',
			[
				'recipient_ids' => [ $this->recipient_id ],
				'message'       => 'Hello, this is the first message.',
			],
			$this->admin_id
		);

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'conversation_id', $data,
			'Response must include conversation_id key.' );
		$this->assertGreaterThan( 0, (int) $data['conversation_id'],
			'conversation_id must be a positive integer.' );
	}

	/**
	 * After a conversation exists, the sender can post a follow-up message
	 * to the conversation thread. The response must be 201.
	 */
	public function test_send_message_to_conversation_returns_201(): void {
		// First create a conversation as admin (bypasses trust gate).
		$create = $this->do_request(
			'POST',
			'/jetonomy/v1/conversations',
			[
				'recipient_ids' => [ $this->recipient_id ],
				'message'       => 'Opening message.',
			],
			$this->admin_id
		);

		$this->assertContains( $create->get_status(), [ 200, 201 ],
			'Conversation creation must succeed before sending a message.' );

		$conv_id = (int) $create->get_data()['conversation_id'];

		// Send a follow-up message as the same admin user.
		$response = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$conv_id}/messages",
			[ 'content' => 'A follow-up message.' ],
			$this->admin_id
		);

		$this->assertEquals( 201, $response->get_status(),
			'Sending a message to an existing conversation must return 201.' );
	}

	/**
	 * A user who is not a participant in a conversation must receive 403
	 * when attempting to read that conversation's messages.
	 */
	public function test_non_participant_cannot_read_conversation(): void {
		// Admin creates a conversation between admin and recipient only.
		$create = $this->do_request(
			'POST',
			'/jetonomy/v1/conversations',
			[
				'recipient_ids' => [ $this->recipient_id ],
				'message'       => 'Private message.',
			],
			$this->admin_id
		);

		$this->assertContains( $create->get_status(), [ 200, 201 ] );

		$conv_id = (int) $create->get_data()['conversation_id'];

		// Outsider: sender_id was not invited to this conversation.
		$response = $this->do_request(
			'GET',
			"/jetonomy/v1/conversations/{$conv_id}",
			[],
			$this->sender_id
		);

		$this->assertEquals( 403, $response->get_status(),
			'A non-participant must receive 403 when reading a conversation.' );
	}

	/**
	 * GET /conversations for a logged-in user must return a response that
	 * contains a "data" key holding an array (even if empty).
	 */
	public function test_list_conversations_returns_data_array(): void {
		$response = $this->do_request(
			'GET',
			'/jetonomy/v1/conversations',
			[],
			$this->admin_id
		);

		$this->assertEquals( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertArrayHasKey( 'data', $body,
			'Response must include a "data" key.' );
		$this->assertIsArray( $body['data'],
			'"data" must be an array.' );
	}
}
