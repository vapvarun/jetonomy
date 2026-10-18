<?php
/**
 * PATCH /users/me refuses a display name before it writes anything, so a
 * rejected save does not leave the profile half updated.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;

class ProfileSaveAtomicTest extends WP_UnitTestCase {

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
		parent::tear_down();
	}

	private function patch( array $body ) {
		$request = new WP_REST_Request( 'PATCH', '/jetonomy/v1/users/me' );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	public function test_refused_display_name_saves_nothing(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Bob', 'first_name' => 'Bob', 'nickname' => 'bob' ) );
		wp_set_current_user( $user_id );

		$res = $this->patch(
			array(
				'nickname'      => 'admin',
				'first_name'    => 'Robert',
				'display_name'  => 'admin',
				'email_opt_out' => 1,
			)
		);

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'bob', get_user_meta( $user_id, 'nickname', true ), 'nickname untouched' );
		$this->assertSame( 'Bob', get_user_meta( $user_id, 'first_name', true ), 'first name untouched' );
		$this->assertEmpty( get_user_meta( $user_id, 'jetonomy_email_opt_out', true ), 'opt-out untouched' );
	}

	/**
	 * @covers-existing Saving new name parts with a matching display name already worked.
	 */
	public function test_new_name_parts_and_matching_display_name_save_together(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'bob', 'first_name' => '', 'last_name' => '' ) );
		wp_set_current_user( $user_id );

		$res = $this->patch(
			array(
				'first_name'   => 'Robert',
				'last_name'    => 'Lee',
				'display_name' => 'Robert Lee',
			)
		);

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Robert Lee', get_userdata( $user_id )->display_name );
		$this->assertSame( 'Lee', get_user_meta( $user_id, 'last_name', true ) );
	}
}
