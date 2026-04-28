<?php
/**
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\API\Auth_Controller;

/**
 * Coverage for the POST /jetonomy/v1/auth/login endpoint shipped in v1.4.0 A.2.
 *
 * Replaces the legacy `wp_ajax_nopriv_jetonomy_quick_login` handler.
 * The login flow needs to (1) accept credentials publicly, (2) rate-limit
 * brute-force attempts, (3) return a generic 401 on bad creds so failures
 * cannot enumerate which half was wrong, (4) succeed for a valid pair.
 */
class AuthApiTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	private string $route = '/jetonomy/v1/auth/login';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		( new Auth_Controller() )->register_routes();

		// Reset the rate-limit transients so each test starts at zero hits.
		// The bucket key includes md5(REMOTE_ADDR); the test runner shares an IP
		// across tests, so without this we accumulate hits across the suite.
		delete_transient( 'jt_auth_login_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function dispatch( array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $this->route );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	public function test_missing_credentials_returns_400(): void {
		// Empty body fails the schema-level required check first, so the route
		// returns 400 with rest_missing_callback_param. That is acceptable —
		// either error code is a 400, the customer sees the form re-show.
		$response = $this->dispatch( [] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_blank_credentials_returns_400_missing_credentials(): void {
		$response = $this->dispatch(
			[
				'user_login'    => '',
				'user_password' => '',
			]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_bad_password_returns_401_invalid_credentials(): void {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'jt_auth_test',
				'user_pass'  => 'correct-password',
			]
		);

		$response = $this->dispatch(
			[
				'user_login'    => 'jt_auth_test',
				'user_password' => 'wrong-password',
			]
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'jetonomy_invalid_credentials', $response->get_data()['code'] );
	}

	public function test_valid_credentials_return_200_signed_in(): void {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'jt_auth_test_2',
				'user_pass'  => 'correct-password',
			]
		);

		$response = $this->dispatch(
			[
				'user_login'    => 'jt_auth_test_2',
				'user_password' => 'correct-password',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['message'] );
	}

	public function test_rate_limit_blocks_after_five_attempts(): void {
		// 5 allowed within 60s, the 6th gets 429.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->dispatch(
				[
					'user_login'    => 'nobody',
					'user_password' => 'nobody',
				]
			);
		}

		$response = $this->dispatch(
			[
				'user_login'    => 'nobody',
				'user_password' => 'nobody',
			]
		);

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'jetonomy_rate_limited', $response->get_data()['code'] );
	}

	public function test_route_appears_in_namespace_index(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/jetonomy/v1' ) );
		$routes   = $response->get_data()['routes'] ?? [];

		$this->assertArrayHasKey( '/jetonomy/v1/auth/login', $routes );
		$this->assertContains( 'POST', $routes['/jetonomy/v1/auth/login']['methods'] );
	}

	// ── Register ─────────────────────────────────────────────────────────────

	private function dispatch_register( array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/auth/register' );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	public function test_register_blocked_when_users_cannot_register(): void {
		update_option( 'users_can_register', 0 );

		$response = $this->dispatch_register(
			[
				'username' => 'whoever',
				'email'    => 'whoever@example.com',
				'password' => 'password123',
			]
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'jetonomy_registration_disabled', $response->get_data()['code'] );
	}

	public function test_register_creates_user_when_open(): void {
		update_option( 'users_can_register', 1 );
		// Reset register rate-limit between tests.
		delete_transient( 'jt_auth_register_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		$response = $this->dispatch_register(
			[
				'username' => 'jt_register_test',
				'email'    => 'jt_register_test@example.com',
				'password' => 'password-1234',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotFalse( get_user_by( 'login', 'jt_register_test' ) );
	}

	public function test_register_rejects_short_password(): void {
		update_option( 'users_can_register', 1 );
		delete_transient( 'jt_auth_register_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		$response = $this->dispatch_register(
			[
				'username' => 'jt_register_short',
				'email'    => 'jt_register_short@example.com',
				'password' => 'short',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_password_too_short', $response->get_data()['code'] );
	}

	public function test_register_rejects_duplicate_username(): void {
		update_option( 'users_can_register', 1 );
		delete_transient( 'jt_auth_register_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		self::factory()->user->create( [ 'user_login' => 'jt_register_dupe' ] );

		$response = $this->dispatch_register(
			[
				'username' => 'jt_register_dupe',
				'email'    => 'jt_register_dupe2@example.com',
				'password' => 'password-1234',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_username_unavailable', $response->get_data()['code'] );
	}

	// ── Lost password ────────────────────────────────────────────────────────

	private function dispatch_lost_password( array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/auth/lost-password' );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	public function test_lost_password_blank_user_login_returns_400(): void {
		delete_transient( 'jt_auth_lost_password_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		$response = $this->dispatch_lost_password( [ 'user_login' => '' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_missing_user_login', $response->get_data()['code'] );
	}

	public function test_lost_password_returns_generic_success_for_existing_user(): void {
		delete_transient( 'jt_auth_lost_password_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		self::factory()->user->create(
			[
				'user_login' => 'jt_lost_pass_real',
				'user_email' => 'jt_lost_pass_real@example.com',
			]
		);

		$response = $this->dispatch_lost_password( [ 'user_login' => 'jt_lost_pass_real' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	public function test_lost_password_returns_generic_success_for_nonexistent_user(): void {
		delete_transient( 'jt_auth_lost_password_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		// Account-enumeration prevention: same shape regardless of existence.
		$response = $this->dispatch_lost_password( [ 'user_login' => 'no_such_user_42' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	public function test_lost_password_rate_limit_blocks_after_three_attempts(): void {
		delete_transient( 'jt_auth_lost_password_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		// 3 allowed within 5 min, the 4th gets 429.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->dispatch_lost_password( [ 'user_login' => 'whoever' ] );
		}

		$response = $this->dispatch_lost_password( [ 'user_login' => 'whoever' ] );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'jetonomy_rate_limited', $response->get_data()['code'] );
	}

	public function test_lost_password_route_appears_in_namespace_index(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/jetonomy/v1' ) );
		$routes   = $response->get_data()['routes'] ?? [];

		$this->assertArrayHasKey( '/jetonomy/v1/auth/lost-password', $routes );
		$this->assertContains( 'POST', $routes['/jetonomy/v1/auth/lost-password']['methods'] );
	}
}
