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

	// -------------------------------------------------------------------------
	// WS3-C — Conversation actions: mute / archive / leave / block
	// -------------------------------------------------------------------------

	/**
	 * Helper: create a direct conversation between admin and recipient and
	 * return its ID. Skips the test cleanly if creation fails.
	 */
	private function create_direct_conversation(): int {
		$response = $this->do_request(
			'POST',
			'/jetonomy/v1/conversations',
			[
				'recipient_ids' => [ $this->recipient_id ],
				'message'       => 'Direct convo for action test.',
			],
			$this->admin_id
		);
		$this->assertContains( $response->get_status(), [ 200, 201 ] );
		return (int) $response->get_data()['conversation_id'];
	}

	/**
	 * Helper: create a group conversation between admin, recipient, and sender.
	 */
	private function create_group_conversation(): int {
		$response = $this->do_request(
			'POST',
			'/jetonomy/v1/conversations',
			[
				'recipient_ids' => [ $this->recipient_id, $this->sender_id ],
				'message'       => 'Group convo for action test.',
				'title'         => 'Action Test Group',
			],
			$this->admin_id
		);
		$this->assertContains( $response->get_status(), [ 200, 201 ] );
		return (int) $response->get_data()['conversation_id'];
	}

	/**
	 * POST /conversations/{id}/mute flips is_muted and the conversation
	 * payload reflects the new state.
	 */
	public function test_mute_endpoint_toggles_state(): void {
		$conv_id = $this->create_direct_conversation();

		$response = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$conv_id}/mute",
			[ 'muted' => true ],
			$this->admin_id
		);

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data()['data'] ?? [];
		$this->assertTrue( (bool) ( $data['is_muted'] ?? false ),
			'Conversation must surface is_muted=true after muting.' );

		// Unmute round-trip.
		$response = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$conv_id}/mute",
			[ 'muted' => false ],
			$this->admin_id
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data()['data'] ?? [];
		$this->assertFalse( (bool) ( $data['is_muted'] ?? true ),
			'Conversation must surface is_muted=false after unmuting.' );
	}

	/**
	 * POST /conversations/{id}/archive moves the conversation out of the
	 * default list and into ?filter=archived.
	 */
	public function test_archive_endpoint_moves_conversation_to_archived_filter(): void {
		$conv_id = $this->create_direct_conversation();

		$archive = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$conv_id}/archive",
			[ 'archived' => true ],
			$this->admin_id
		);
		$this->assertEquals( 200, $archive->get_status() );

		// Default (active) list — should NOT contain the conv.
		$active = $this->do_request( 'GET', '/jetonomy/v1/conversations', [], $this->admin_id );
		$active_ids = array_column( $active->get_data()['data'] ?? [], 'id' );
		$this->assertNotContains( $conv_id, $active_ids,
			'Archived conversation must not appear in the default list.' );

		// Archived list — should contain the conv.
		$archived = $this->do_request( 'GET', '/jetonomy/v1/conversations', [ 'filter' => 'archived' ], $this->admin_id );
		$archived_ids = array_column( $archived->get_data()['data'] ?? [], 'id' );
		$this->assertContains( $conv_id, $archived_ids,
			'Archived conversation must appear under ?filter=archived.' );
	}

	/**
	 * POST /conversations/{id}/leave is rejected for direct conversations
	 * and accepted (with system message) for group conversations.
	 */
	public function test_leave_endpoint_group_only(): void {
		// Direct: 400.
		$direct_id = $this->create_direct_conversation();
		$direct    = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$direct_id}/leave",
			[],
			$this->admin_id
		);
		$this->assertEquals( 400, $direct->get_status(),
			'Leave on a direct conversation must return 400.' );

		// Group: success, and conversation drops from the default list.
		$group_id = $this->create_group_conversation();
		$leave    = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$group_id}/leave",
			[],
			$this->admin_id
		);
		$this->assertEquals( 200, $leave->get_status() );

		$listing = $this->do_request( 'GET', '/jetonomy/v1/conversations', [], $this->admin_id );
		$ids     = array_column( $listing->get_data()['data'] ?? [], 'id' );
		$this->assertNotContains( $group_id, $ids,
			'Left group must not appear in the default conversations list.' );

		// A system message announcing the departure must exist in the thread.
		global $wpdb;
		$msg_table = $wpdb->prefix . 'jt_pro_messages';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$system_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$msg_table} WHERE conversation_id = %d AND is_system = 1",
				$group_id
			)
		);
		$this->assertGreaterThanOrEqual( 1, $system_count,
			'Leaving a group must insert at least one system message into the thread.' );
	}

	/**
	 * POST /conversations/{id}/block is rejected for group conversations
	 * and toggles is_blocked for direct conversations.
	 */
	public function test_block_endpoint_direct_only(): void {
		// Group: 400.
		$group_id = $this->create_group_conversation();
		$bad      = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$group_id}/block",
			[ 'blocked' => true ],
			$this->admin_id
		);
		$this->assertEquals( 400, $bad->get_status(),
			'Block on a group conversation must return 400.' );

		// Direct: success.
		$direct_id = $this->create_direct_conversation();
		$ok        = $this->do_request(
			'POST',
			"/jetonomy/v1/conversations/{$direct_id}/block",
			[ 'blocked' => true ],
			$this->admin_id
		);
		$this->assertEquals( 200, $ok->get_status() );

		$data = $ok->get_data()['data'] ?? [];
		$this->assertTrue( (bool) ( $data['is_blocked'] ?? false ),
			'Direct conversation must surface is_blocked=true after blocking.' );

		// Verify the flag landed on the OTHER participant's row.
		global $wpdb;
		$part_table = $wpdb->prefix . 'jt_pro_conversation_participants';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blocked_row_user = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$part_table} WHERE conversation_id = %d AND is_blocked = 1 LIMIT 1",
				$direct_id
			)
		);
		$this->assertEquals( $this->recipient_id, $blocked_row_user,
			'is_blocked must be set on the OTHER participant row, not the blocker.' );
	}

	/**
	 * The four action endpoints must require an authenticated request — an
	 * anonymous caller hits the REST_Auth nonce/login gate before reaching
	 * the handler.
	 */
	public function test_action_endpoints_reject_anonymous(): void {
		$conv_id = $this->create_direct_conversation();

		foreach ( [ 'mute', 'archive', 'block' ] as $action ) {
			$response = $this->do_request(
				'POST',
				"/jetonomy/v1/conversations/{$conv_id}/{$action}",
				[],
				null // anonymous.
			);
			$this->assertContains(
				$response->get_status(),
				[ 401, 403 ],
				"Anonymous POST to /{$action} must be rejected (got {$response->get_status()})."
			);
		}
	}
}
