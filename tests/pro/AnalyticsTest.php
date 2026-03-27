<?php
/**
 * Integration tests for the Analytics Pro extension.
 *
 * Exercises GET /jetonomy/v1/analytics/overview to verify that:
 * - An administrator (with the jetonomy_view_analytics capability) receives 200.
 * - A subscriber (without that capability) receives 403.
 *
 * The extension grants jetonomy_view_analytics to the administrator role
 * inside its activate() method. Because WP_UnitTestCase rolls back users
 * and options between tests but NOT role capabilities, we grant the cap
 * explicitly to the admin user object in set_up() to stay test-isolated.
 *
 * Skipped automatically when Jetonomy Pro is not active.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;

class AnalyticsTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int Administrator with analytics access. */
	private int $admin_id;

	/** @var int Subscriber without analytics access. */
	private int $subscriber_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — analytics tests skipped.' );
		}

		Schema::create_tables();

		// Run extension activate() to ensure the capability is registered on
		// the administrator role and REST routes are known to the test server.
		if ( class_exists( 'Jetonomy_Pro\Extensions\Analytics\Extension' ) ) {
			$ext = new \Jetonomy_Pro\Extensions\Analytics\Extension();
			$ext->activate();
		}

		// Bootstrap REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create users.
		$this->admin_id      = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		// Explicitly grant the analytics cap to the admin user object so the
		// test is not dependent on role-level caps surviving between test runs.
		$admin_user = get_user_by( 'id', $this->admin_id );
		$admin_user->add_cap( 'jetonomy_view_analytics' );
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
	 * Dispatch GET /jetonomy/v1/analytics/overview.
	 *
	 * @param int|null $user_id Authenticated user, or null for guest.
	 * @return \WP_REST_Response
	 */
	private function get_overview( ?int $user_id = null ): \WP_REST_Response {
		wp_set_current_user( $user_id ?? 0 );

		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/analytics/overview' );

		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * An administrator with the jetonomy_view_analytics capability must
	 * receive HTTP 200 from the overview endpoint.
	 */
	public function test_admin_can_view_analytics_overview(): void {
		$response = $this->get_overview( $this->admin_id );

		$this->assertEquals( 200, $response->get_status(),
			'Administrator with jetonomy_view_analytics cap must receive 200.' );
	}

	/**
	 * A subscriber who lacks jetonomy_view_analytics must receive 403.
	 */
	public function test_subscriber_cannot_view_analytics_overview(): void {
		$response = $this->get_overview( $this->subscriber_id );

		$this->assertEquals( 403, $response->get_status(),
			'Subscriber without the analytics capability must receive 403.' );
	}
}
