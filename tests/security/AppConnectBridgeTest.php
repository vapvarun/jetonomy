<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\API\Auth_Controller;
use Jetonomy\DB\Schema;
use Jetonomy\Integrations\App_Connect;

/**
 * POST /jetonomy/v1/auth/app-connect — the standalone bridge's mint step.
 *
 * Wbcom App Auth standard, phase J3 (docs/standards/app-auth.md). This
 * endpoint hands out LIVE CREDENTIALS, so every gate is a security boundary
 * and each carries a test: the session, the scheme allowlist, the one-time
 * member-bound bridge token, the per-user mint cap, and — via
 * REST_Auth::auth_mutation — the ban gate, because Application Passwords
 * authenticate OUTSIDE the wp_authenticate flow and a banned member must not
 * be able to mint a fresh way in. The replay case matters most: a consumed
 * token re-minting would turn one leaked URL into an unlimited credential
 * mint.
 *
 * (On sites running BuddyNext this route does not register at all — one door
 * per site; BN's bridge and mint serve every app. BN is absent from this
 * suite, which is exactly the standalone topology under test.)
 */
class AppConnectBridgeTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	private string $route = '/jetonomy/v1/auth/app-connect';

	private int $user_id = 0;

	private const APP_ID = '9a1b2c30-4d5e-4f60-8a7b-8c9d0e1f2a3b';

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		do_action( 'rest_api_init' );
		( new Auth_Controller() )->register_routes();

		$this->user_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->user_id );
		delete_transient( 'jt_app_connect_' . $this->user_id );

		// Core gates Application Passwords on HTTPS / 'local' env; the harness
		// is plain-HTTP 'production'. Real deployments are HTTPS.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_all_filters( 'jetonomy_app_connect_schemes' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function mint( array $overrides = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $this->route );
		$params  = array_merge(
			[
				'scheme'       => 'jetonomyapp',
				'bridge_token' => App_Connect::issue_bridge_token(),
				'app_name'     => 'Jetonomy',
				'app_id'       => self::APP_ID,
				'state'        => 'app-nonce-1',
			],
			$overrides
		);
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Happy path: WP-core-shaped deep link on the requested scheme with the
	 * state echoed, exactly one credential row, and an uncacheable response.
	 */
	public function test_mints_and_returns_the_core_shaped_deep_link(): void {
		$response = $this->mint();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$viewer = get_userdata( $this->user_id );
		$this->assertSame( home_url(), $data['site_url'] );
		$this->assertSame( $viewer->user_login, $data['user_login'] );
		$this->assertNotEmpty( $data['uuid'] );

		$this->assertStringStartsWith( 'jetonomyapp://auth?', $data['deep_link'] );
		$this->assertStringContainsString( 'user_login=', $data['deep_link'] );
		$this->assertStringContainsString( 'password=', $data['deep_link'] );
		$this->assertStringContainsString(
			'state=app-nonce-1',
			$data['deep_link'],
			'the app state must be echoed so the app can reject redirects it never started'
		);

		$rows = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Jetonomy', $rows[0]['name'] );
		$this->assertSame( self::APP_ID, $rows[0]['app_id'] );

		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'], 'a live credential must never be cacheable' );
	}

	/**
	 * Anonymous requests never reach the handler, and mint nothing.
	 */
	public function test_anonymous_request_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->mint();
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( [], \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );
	}

	/**
	 * A BANNED member cannot mint a fresh credential. Application Passwords
	 * authenticate outside wp_authenticate, so without this gate a ban would
	 * only close the doors the member was not using.
	 */
	public function test_banned_member_cannot_mint(): void {
		\Jetonomy\Models\Restriction::ban( $this->user_id, 'global_ban', 1 );

		$response = $this->mint();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'jetonomy_user_banned', $response->get_data()['code'] );
		$this->assertSame( [], \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );
	}

	/**
	 * Only allowlisted schemes may receive a credential; a filter-registered
	 * sibling scheme is how the other Wbcom apps join.
	 */
	public function test_scheme_allowlist(): void {
		foreach ( [ 'javascript', 'https', 'someoneelsesapp', 'BAD SCHEME' ] as $bad ) {
			$response = $this->mint( [ 'scheme' => $bad ] );
			$this->assertSame( 400, $response->get_status(), $bad . ' must be refused' );
			$this->assertSame( 'jetonomy_app_bad_scheme', $response->get_data()['code'] );
		}
		$this->assertSame( [], \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );

		add_filter(
			'jetonomy_app_connect_schemes',
			static function ( array $schemes ): array {
				$schemes[] = 'learnomyapp';
				return $schemes;
			}
		);

		$response = $this->mint( [ 'scheme' => 'learnomyapp' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'learnomyapp://auth?', $response->get_data()['deep_link'] );
	}

	/**
	 * The bridge token is single-use — a replay is 410 and mints NOTHING —
	 * and bound to the member it was issued for.
	 */
	public function test_bridge_token_is_single_use_and_member_bound(): void {
		$token = App_Connect::issue_bridge_token();

		$this->assertSame( 200, $this->mint( [ 'bridge_token' => $token ] )->get_status() );

		$replay = $this->mint( [ 'bridge_token' => $token ] );
		$this->assertSame( 410, $replay->get_status() );
		$this->assertSame( 'jetonomy_app_bridge_expired', $replay->get_data()['code'] );
		$this->assertCount(
			1,
			\WP_Application_Passwords::get_user_application_passwords( $this->user_id ),
			'the replay must not mint a second credential'
		);

		// Issued for THIS member, presented by another: refused.
		$foreign = App_Connect::issue_bridge_token();
		$other   = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other );

		$this->assertSame( 410, $this->mint( [ 'bridge_token' => $foreign ] )->get_status() );
		$this->assertSame( [], \WP_Application_Passwords::get_user_application_passwords( $other ) );
	}

	/**
	 * Reconnecting the same device REPLACES its credential row.
	 */
	public function test_reconnect_replaces_the_device_credential(): void {
		$first = $this->mint()->get_data();
		$this->mint();
		$this->mint();

		$rows = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $rows, 'three connects of one device must leave exactly one credential row' );
		$this->assertNotSame( $first['uuid'], $rows[0]['uuid'], 'the surviving row is the newest mint' );
	}

	/**
	 * The per-user mint cap holds: the sixth mint in the window is refused.
	 */
	public function test_rate_limit_caps_minting(): void {
		$last = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$last = $this->mint();
		}

		$this->assertSame( 429, $last->get_status() );
		$this->assertSame( 'jetonomy_rate_limited', $last->get_data()['code'] );
	}
}
