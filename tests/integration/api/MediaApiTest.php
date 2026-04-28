<?php
/**
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\API\Media_Controller;

/**
 * Coverage for the POST /jetonomy/v1/media endpoint shipped in v1.4.0 A.1.
 *
 * The Media_Controller replaces the legacy `wp_ajax_jetonomy_upload_image`
 * AJAX handler. The permission matrix is the load-bearing piece — must mirror
 * what `Jetonomy\Media::handle_upload` accepted so the migration is a pure
 * transport change with zero behaviour drift.
 */
class MediaApiTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	private string $route = '/jetonomy/v1/media';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		( new Media_Controller() )->register_routes();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_anonymous_request_is_rejected_with_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', $this->route ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'jetonomy_unauthenticated', $response->get_data()['code'] );
	}

	public function test_user_without_any_upload_cap_is_rejected_with_403(): void {
		// Register a brand-new role that has zero Jetonomy caps and zero upload_files.
		add_role( 'jt_no_caps', 'No Caps', [] );
		$user_id = self::factory()->user->create( [ 'role' => 'jt_no_caps' ] );
		wp_set_current_user( $user_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', $this->route ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'jetonomy_forbidden', $response->get_data()['code'] );

		remove_role( 'jt_no_caps' );
	}

	public function test_subscriber_with_jetonomy_create_posts_passes_permission_check(): void {
		// Subscribers receive `jetonomy_create_posts` via Capabilities::register, mirroring
		// the legacy AJAX handler so anyone allowed to write a topic can attach an image.
		\Jetonomy\Permissions\Capabilities::register();
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		// Without a $_FILES['file'] payload, the handler fast-fails with 400 — but only
		// AFTER the permission check passes. A 401/403 here would mean Subscribers got
		// blocked, breaking parity with the legacy handler.
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', $this->route ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_no_file', $response->get_data()['code'] );

		\Jetonomy\Permissions\Capabilities::unregister();
	}

	public function test_author_with_upload_files_passes_permission_check(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $user_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', $this->route ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'jetonomy_no_file', $response->get_data()['code'] );
	}

	public function test_route_appears_in_namespace_index(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/jetonomy/v1' ) );
		$routes   = $response->get_data()['routes'] ?? [];

		$this->assertArrayHasKey( '/jetonomy/v1/media', $routes );
		$this->assertContains( 'POST', $routes['/jetonomy/v1/media']['methods'] );
	}
}
