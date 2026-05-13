<?php
/**
 * Unit tests for Jetonomy\API\REST_Auth.
 *
 * @package Jetonomy
 * @since   1.4.3
 */

namespace Jetonomy\Tests\Unit\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_Error;
use Jetonomy\API\REST_Auth;

class RestAuthTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		// Clean any leaked auth-mode globals from previous tests so the
		// cookie-vs-header detection in REST_Auth is deterministic.
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		$_COOKIE = array();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		$_COOKIE = array();
		parent::tear_down();
	}

	/** auth_mutation returns 401 when the request is anonymous. */
	public function test_auth_mutation_returns_401_when_logged_out(): void {
		$cb     = REST_Auth::auth_mutation();
		$result = $cb( new WP_REST_Request( 'POST', '/jetonomy/v1/posts' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/** auth_mutation returns 403 when cookie-authenticated but X-WP-Nonce is missing. */
	public function test_auth_mutation_returns_403_when_cookie_auth_missing_nonce(): void {
		wp_set_current_user( $this->user_id );
		// Simulate cookie auth: WP cookie present, no header auth.
		$_COOKIE['wordpress_logged_in_test'] = '1';

		$cb     = REST_Auth::auth_mutation();
		$result = $cb( new WP_REST_Request( 'POST', '/jetonomy/v1/posts' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** auth_mutation returns 403 when nonce is valid but the user lacks the required cap. */
	public function test_auth_mutation_returns_403_when_cap_missing(): void {
		wp_set_current_user( $this->user_id );
		$_COOKIE['wordpress_logged_in_test'] = '1';

		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/posts' );
		$request->add_header( 'x_wp_nonce', wp_create_nonce( 'wp_rest' ) );

		$cb     = REST_Auth::auth_mutation( 'manage_options' );
		$result = $cb( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** auth_mutation passes when nonce is valid and the user holds the required cap. */
	public function test_auth_mutation_passes_with_valid_nonce_and_cap(): void {
		wp_set_current_user( $this->user_id );
		$_COOKIE['wordpress_logged_in_test'] = '1';

		$user = get_user_by( 'id', $this->user_id );
		$user->add_cap( 'jetonomy_create_posts' );

		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/posts' );
		$request->add_header( 'x_wp_nonce', wp_create_nonce( 'wp_rest' ) );

		$cb     = REST_Auth::auth_mutation( 'jetonomy_create_posts' );
		$result = $cb( $request );

		$this->assertTrue( $result );
	}

	/**
	 * auth_public_write always passes (rate limiting is captured but not yet
	 * enforced — see TODO in REST_Auth::auth_public_write).
	 */
	public function test_auth_public_write_always_passes(): void {
		$cb = REST_Auth::auth_public_write( array( 'rate_limit' => 'quick_register' ) );

		// Logged-out caller — still passes.
		$result_anon = $cb( new WP_REST_Request( 'POST', '/jetonomy/v1/auth/register' ) );
		$this->assertTrue( $result_anon );

		// Logged-in caller — also passes (no extra checks).
		wp_set_current_user( $this->user_id );
		$result_user = $cb( new WP_REST_Request( 'POST', '/jetonomy/v1/auth/register' ) );
		$this->assertTrue( $result_user );
	}
}
