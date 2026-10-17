<?php
/**
 * A banned or silenced member who tries to upload media is told why in the
 * same words as every other refused write (Permission_Engine::restriction_error()).
 *
 * @package Jetonomy\Tests\Integration\Api
 */

namespace Jetonomy\Tests\Integration\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\API\Media_Controller;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Restriction;

class MediaUploadRestrictionTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Permissions\Capabilities::register();

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

	public function test_silenced_member_upload_uses_the_shared_restriction_wording(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$uid   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Restriction::ban( $uid, 'silence', $admin );
		wp_set_current_user( $uid );

		$res      = $this->server->dispatch( new WP_REST_Request( 'POST', '/jetonomy/v1/media' ) );
		$expected = \Jetonomy\Permissions\Permission_Engine::restriction_error( $uid, 'create_posts' );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( $expected->get_error_code(), $res->get_data()['code'] );
		$this->assertSame( $expected->get_error_message(), $res->get_data()['message'] );
	}
}
