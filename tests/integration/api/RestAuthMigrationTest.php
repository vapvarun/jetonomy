<?php
/**
 * Cross-route auth gate for the WS2-B mutation migration (v1.4.3).
 *
 * Every mutation route now flows through REST_Auth::auth_mutation() (or
 * auth_public_write() for /auth/*). This test enforces the contract by
 * walking a representative sample of routes and asserting the three failure
 * modes one expects from the helper:
 *   1. anonymous request → 401 rest_not_logged_in
 *   2. logged in, no nonce → 403 rest_cookie_invalid_nonce
 *   3. logged in, no cap → 403 rest_forbidden  (for cap-gated routes only)
 *
 * Bookmarks/users/me-PATCH routes use `auth_mutation('read')` which has no
 * effective cap check (every logged-in user can `read`), so they only verify
 * cases 1 and 2.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;

class RestAuthMigrationTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Routes covered by the migration. Format:
	 *   [ method, route, body, required_cap_or_null ]
	 *
	 * Cap == null means the route uses `auth_mutation('read')` (login + nonce only).
	 * Cap == string means the route requires that specific capability.
	 */
	public static function routes_provider(): array {
		return array(
			'admin recount'                  => array( 'POST', '/jetonomy/v1/admin/recount', array( 'type' => 'all' ), 'manage_options' ),
			'admin bulk trust'               => array( 'POST', '/jetonomy/v1/admin/users/trust-level', array( 'user_ids' => array( 1 ), 'level' => 1 ), 'manage_options' ),
			'bookmarks toggle'               => array( 'POST', '/jetonomy/v1/bookmarks', array( 'post_id' => 1 ), null ),
			'bookmarks delete'               => array( 'DELETE', '/jetonomy/v1/bookmarks/1', array(), null ),
			'category create'                => array( 'POST', '/jetonomy/v1/categories', array( 'name' => 't' ), 'jetonomy_manage_categories' ),
			'category update'                => array( 'PATCH', '/jetonomy/v1/categories/1', array( 'name' => 't' ), 'jetonomy_manage_categories' ),
			'category delete'                => array( 'DELETE', '/jetonomy/v1/categories/1', array(), 'jetonomy_manage_categories' ),
			'media upload'                   => array( 'POST', '/jetonomy/v1/media', array(), null ),
			'moderation approve'             => array( 'POST', '/jetonomy/v1/moderation/approve/post/1', array(), 'jetonomy_moderate' ),
			'moderation spam'                => array( 'POST', '/jetonomy/v1/moderation/spam/post/1', array(), 'jetonomy_moderate' ),
			'moderation trash'               => array( 'POST', '/jetonomy/v1/moderation/trash/post/1', array(), 'jetonomy_moderate' ),
			'moderation bulk'                => array( 'POST', '/jetonomy/v1/moderation/bulk', array( 'action' => 'approve', 'items' => array( array( 'type' => 'post', 'id' => 1 ) ) ), 'jetonomy_moderate' ),
			'moderation unban'               => array( 'DELETE', '/jetonomy/v1/moderation/ban/1', array(), 'jetonomy_moderate' ),
			'flag create'                    => array( 'POST', '/jetonomy/v1/flags', array( 'object_type' => 'post', 'object_id' => 1, 'reason' => 'spam' ), 'jetonomy_flag' ),
			'flag resolve'                   => array( 'POST', '/jetonomy/v1/moderation/flags/1/resolve', array( 'status' => 'dismissed' ), 'jetonomy_moderate' ),
			'notifications mark all'         => array( 'POST', '/jetonomy/v1/notifications/mark-all-read', array(), null ),
			'notifications mark one'         => array( 'PATCH', '/jetonomy/v1/notifications/1', array(), null ),
			'post create'                    => array( 'POST', '/jetonomy/v1/spaces/1/posts', array( 'title' => 'x', 'content' => 'y' ), null ),
			'post update'                    => array( 'PATCH', '/jetonomy/v1/posts/1', array( 'title' => 'x' ), null ),
			'post delete'                    => array( 'DELETE', '/jetonomy/v1/posts/1', array(), null ),
			'post close'                     => array( 'POST', '/jetonomy/v1/posts/1/close', array(), null ),
			'post pin'                       => array( 'POST', '/jetonomy/v1/posts/1/pin', array(), null ),
			'post move'                      => array( 'POST', '/jetonomy/v1/posts/1/move', array( 'target_space_id' => 2 ), null ),
			'post merge'                     => array( 'POST', '/jetonomy/v1/posts/1/merge', array( 'target_post_id' => 2 ), null ),
			'post idea status'               => array( 'POST', '/jetonomy/v1/posts/1/idea-status', array( 'idea_status' => 'planned' ), null ),
			'reply create'                   => array( 'POST', '/jetonomy/v1/posts/1/replies', array( 'content' => 'x' ), null ),
			'reply update'                   => array( 'PATCH', '/jetonomy/v1/replies/1', array( 'content' => 'x' ), null ),
			'reply delete'                   => array( 'DELETE', '/jetonomy/v1/replies/1', array(), null ),
			'reply accept'                   => array( 'POST', '/jetonomy/v1/replies/1/accept', array(), null ),
			'reply split'                    => array( 'POST', '/jetonomy/v1/replies/1/split', array( 'title' => 'x' ), null ),
			'space create'                   => array( 'POST', '/jetonomy/v1/spaces', array( 'title' => 'x' ), null ),
			'space update'                   => array( 'PATCH', '/jetonomy/v1/spaces/1', array( 'title' => 'x' ), null ),
			'space delete'                   => array( 'DELETE', '/jetonomy/v1/spaces/1', array(), null ),
			'space join'                     => array( 'POST', '/jetonomy/v1/spaces/1/members', array(), null ),
			'space invite'                   => array( 'POST', '/jetonomy/v1/spaces/1/invite', array(), null ),
			'space leave'                    => array( 'DELETE', '/jetonomy/v1/spaces/1/members/2', array(), null ),
			'space member role'              => array( 'PATCH', '/jetonomy/v1/spaces/1/members/2', array( 'role' => 'member' ), null ),
			'subscription create'            => array( 'POST', '/jetonomy/v1/subscriptions', array( 'object_type' => 'post', 'object_id' => 1 ), null ),
			'subscription delete'            => array( 'DELETE', '/jetonomy/v1/subscriptions/1', array(), null ),
			'user me update'                 => array( 'PATCH', '/jetonomy/v1/users/me', array( 'bio' => 'x' ), null ),
			'vote post'                      => array( 'POST', '/jetonomy/v1/posts/1/vote', array( 'value' => 1 ), null ),
			'unvote post'                    => array( 'DELETE', '/jetonomy/v1/posts/1/vote', array(), null ),
			'vote reply'                     => array( 'POST', '/jetonomy/v1/replies/1/vote', array( 'value' => 1 ), null ),
			'unvote reply'                   => array( 'DELETE', '/jetonomy/v1/replies/1/vote', array(), null ),
			'space moderation flag resolve'  => array( 'POST', '/jetonomy/v1/spaces/1/moderation/flags/1/resolve', array( 'status' => 'dismissed' ), null ),
			'space moderation act'           => array( 'POST', '/jetonomy/v1/spaces/1/moderation/approve/post/1', array(), null ),
		);
	}

	/**
	 * Anonymous callers should be 401 rest_not_logged_in.
	 *
	 * @dataProvider routes_provider
	 */
	public function test_route_rejects_anonymous( string $method, string $route, array $body, ?string $cap ): void {
		unset( $cap ); // not used in this case.

		wp_set_current_user( 0 );
		$response = $this->dispatch( $method, $route, $body );
		$this->assertSame(
			401,
			$response->get_status(),
			"Route {$method} {$route} must return 401 to anonymous callers (got " . $response->get_status() . ')'
		);
		$err = $response->as_error();
		if ( $err ) {
			$this->assertSame( 'rest_not_logged_in', $err->get_error_code(), "Route {$method} {$route} returned wrong error code" );
		}
	}

	/**
	 * Logged-in callers without a valid X-WP-Nonce should be 403
	 * rest_cookie_invalid_nonce, since these are cookie-auth requests.
	 *
	 * @dataProvider routes_provider
	 */
	public function test_route_rejects_missing_nonce( string $method, string $route, array $body, ?string $cap ): void {
		unset( $cap );

		// Use a subscriber: lowest-privilege logged-in role. Force the
		// REST request through the cookie-auth path by populating $_COOKIE.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'dev-cookie';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );

		$response = $this->dispatch( $method, $route, $body );
		// Without a nonce we expect 403 rest_cookie_invalid_nonce. A small
		// number of routes (e.g. unauthenticated GETs) won't trip the gate;
		// those are read routes and never reach this test via the provider.
		$this->assertSame(
			403,
			$response->get_status(),
			"Route {$method} {$route} must return 403 without nonce (got " . $response->get_status() . ')'
		);
		$err = $response->as_error();
		if ( $err ) {
			$this->assertSame(
				'rest_cookie_invalid_nonce',
				$err->get_error_code(),
				"Route {$method} {$route} returned {$err->get_error_code()}, expected rest_cookie_invalid_nonce"
			);
		}

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	/**
	 * Logged-in callers with a nonce but no cap should be 403 rest_forbidden
	 * (only meaningful for cap-gated routes).
	 *
	 * @dataProvider routes_provider
	 */
	public function test_route_rejects_missing_cap( string $method, string $route, array $body, ?string $cap ): void {
		if ( null === $cap || 'read' === $cap ) {
			// auth_mutation('read') routes don't gate on cap — every logged-in
			// user has `read`. Nothing to assert here.
			$this->assertTrue( true );
			return;
		}

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Subscribers hold several jetonomy_* caps by ROLE design (members
		// can flag, vote, etc. — see Capabilities::CAPABILITY_MAP). To test
		// the 403 gate, explicitly DENY this cap on the user; add_cap with
		// false overrides the role grant in WP's cap resolution.
		( new \WP_User( $user_id ) )->add_cap( $cap, false );

		wp_set_current_user( $user_id );
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'dev-cookie';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );

		// Forge a valid nonce on behalf of the test user. The REST nonce
		// derives from user ID + 'wp_rest' action, so create_nonce() inside
		// the test runtime returns one that auth_mutation() will accept.
		$nonce = wp_create_nonce( 'wp_rest' );

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', $nonce );
		if ( ! empty( $body ) ) {
			$request->set_body_params( $body );
		}
		$response = $this->server->dispatch( $request );

		$this->assertSame(
			403,
			$response->get_status(),
			"Route {$method} {$route} must return 403 without cap {$cap} (got " . $response->get_status() . ')'
		);
		$err = $response->as_error();
		if ( $err ) {
			$this->assertSame(
				'rest_forbidden',
				$err->get_error_code(),
				"Route {$method} {$route} returned {$err->get_error_code()}, expected rest_forbidden"
			);
		}

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	private function dispatch( string $method, string $route, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( ! empty( $body ) ) {
			$request->set_body_params( $body );
		}
		return $this->server->dispatch( $request );
	}
}
