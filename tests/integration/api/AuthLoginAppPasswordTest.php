<?php
namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\API\Auth_Controller;

/**
 * issue_app_password on POST /jetonomy/v1/auth/login — the native-app path.
 *
 * Wbcom App Auth standard (docs/standards/app-auth.md, phase J2 of the
 * rollout plan): a member signs into the Jetonomy app with their WordPress
 * password ONCE, receives a WP core Application Password, and authenticates
 * everything after over HTTP Basic. The properties pinned here are the
 * standard's: mint only after full sign-on success (wrong password mints
 * NOTHING), reconnect REPLACES the device row instead of stacking live
 * credentials, no-store on the credential response, and byte-for-byte
 * back-compat for the web login that does not send the parameter.
 */
class AuthLoginAppPasswordTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	private string $route = '/jetonomy/v1/auth/login';

	private const APP_ID = '7c3c8a10-9d2e-4f6b-8a1c-2e5d7b9f0a34';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		do_action( 'rest_api_init' );
		( new Auth_Controller() )->register_routes();

		delete_transient( 'jt_auth_login_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );

		// Core gates Application Passwords on HTTPS (or a 'local' environment
		// type) and the harness is plain-HTTP 'production'. Real deployments
		// are HTTPS; make the harness match so these tests exercise the mint,
		// not core's transport gate.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		// wp_authenticate_application_password() refuses to even LOOK at the
		// credential outside an API request (it returns its first argument
		// untouched), and PHPUnit is not serving REST. Flip core's own
		// is-api-request filter so the authenticate round trip below tests
		// the credential, not the request context.
		add_filter( 'application_password_is_api_request', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function login( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $this->route );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	private function member( string $login ): int {
		return (int) self::factory()->user->create(
			[
				'user_login' => $login,
				'user_pass'  => 'correct-password',
				'role'       => 'subscriber',
			]
		);
	}

	/**
	 * The full loop: right password + issue_app_password mints a credential
	 * that actually AUTHENTICATES over Basic, and the response is no-store.
	 */
	public function test_mints_a_working_credential(): void {
		$user_id = $this->member( 'jt_app_member' );

		$response = $this->login(
			[
				'user_login'         => 'jt_app_member',
				'user_password'      => 'correct-password',
				'issue_app_password' => true,
				'device_name'        => 'Jetonomy on iPhone',
				'app_id'             => self::APP_ID,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$minted = $data['app_password'];
		$this->assertSame( 'jt_app_member', $minted['username'] );
		$this->assertSame( 'Jetonomy on iPhone', $minted['name'] );
		$this->assertNotEmpty( $minted['password'] );
		$this->assertNotEmpty( $minted['uuid'] );

		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'], 'a live credential must never be cacheable' );

		// The credential authenticates: core's own validator accepts it for
		// this member. wp_authenticate_application_password is what Basic
		// auth resolves through on a real request.
		$rows = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( self::APP_ID, $rows[0]['app_id'], 'the per-install app_id must be stored on the row' );

		$check = wp_authenticate_application_password( null, 'jt_app_member', (string) $minted['password'] );
		$this->assertInstanceOf( \WP_User::class, $check, 'the minted password must authenticate' );
		$this->assertSame( $user_id, $check->ID );
	}

	/**
	 * Wrong password mints NOTHING — the mint sits strictly behind
	 * wp_signon(), so every authenticate-chain gate (ban, pending
	 * verification, rate limit) fails closed with it.
	 */
	public function test_wrong_password_mints_nothing(): void {
		$user_id = $this->member( 'jt_app_member_2' );

		$response = $this->login(
			[
				'user_login'         => 'jt_app_member_2',
				'user_password'      => 'wrong-password',
				'issue_app_password' => true,
				'app_id'             => self::APP_ID,
			]
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame(
			[],
			\WP_Application_Passwords::get_user_application_passwords( $user_id ),
			'a failed login must never leave a credential behind'
		);
	}

	/**
	 * Reconnecting the same device REPLACES its credential row (matched by
	 * the stable app_id) instead of stacking live credentials.
	 */
	public function test_reconnect_replaces_the_device_row(): void {
		$user_id = $this->member( 'jt_app_member_3' );
		$body    = [
			'user_login'         => 'jt_app_member_3',
			'user_password'      => 'correct-password',
			'issue_app_password' => true,
			'device_name'        => 'Jetonomy on iPhone',
			'app_id'             => self::APP_ID,
		];

		$first = $this->login( $body )->get_data()['app_password'];
		$this->login( $body );
		$this->login( $body );

		$rows = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$this->assertCount( 1, $rows, 'three connects of one device must leave exactly one credential row' );
		$this->assertNotSame( $first['uuid'], $rows[0]['uuid'], 'the surviving row is the newest mint' );
	}

	/**
	 * The web login is byte-for-byte unchanged when the parameter is absent —
	 * no app_password key, no cache-control header, same message.
	 */
	public function test_web_login_response_is_unchanged(): void {
		$this->member( 'jt_app_member_4' );

		$response = $this->login(
			[
				'user_login'    => 'jt_app_member_4',
				'user_password' => 'correct-password',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayNotHasKey( 'app_password', $data );
		$this->assertArrayNotHasKey( 'Cache-Control', $response->get_headers() );
	}

	/**
	 * A malformed app_id is dropped rather than stored: the row simply
	 * carries no app_id, and matching falls back to the label.
	 */
	public function test_malformed_app_id_is_not_stored(): void {
		$user_id = $this->member( 'jt_app_member_5' );

		$this->login(
			[
				'user_login'         => 'jt_app_member_5',
				'user_password'      => 'correct-password',
				'issue_app_password' => true,
				'app_id'             => 'not-a-uuid',
			]
		);

		$rows = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( '', (string) $rows[0]['app_id'] );
	}
}
