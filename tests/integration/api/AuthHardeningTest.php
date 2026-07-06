<?php
/**
 * Integration test: REST_Auth account-status gate (1.6.0 item 8).
 *
 * Application Passwords mint credentials outside wp_authenticate, so the
 * `authenticate`-filter ban / pending-verification gates never run for an
 * app/API mutation. REST_Auth::auth_mutation() must enforce them itself so one
 * place covers every write route (posts, replies, votes, push registration).
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Restriction;

class AuthHardeningTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $admin_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// Grant the caps a normal create would need, so the ONLY thing that can
		// block the write is the account-status gate under test.
		$user = get_user_by( 'id', $this->user_id );
		foreach ( array( 'jetonomy_read', 'jetonomy_create_posts' ) as $cap ) {
			$user->add_cap( $cap );
		}

		$cat            = Category::create(
			array(
				'name' => 'Auth Cat',
				'slug' => 'auth-cat-' . uniqid(),
			)
		);
		$this->space_id = Space::create(
			array(
				'title'       => 'Auth Space',
				'slug'        => 'auth-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);
		SpaceMember::add( $this->space_id, $this->user_id, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function create_post_as( int $uid ): \WP_REST_Response {
		wp_set_current_user( $uid );
		$req = new WP_REST_Request( 'POST', "/jetonomy/v1/spaces/{$this->space_id}/posts" );
		$req->set_body_params(
			array(
				'title'   => 'Should be blocked',
				'content' => 'Body content here',
			)
		);
		return $this->server->dispatch( $req );
	}

	public function test_banned_user_blocked_on_writes(): void {
		Restriction::ban( $this->user_id, 'global_ban', $this->admin_id );

		$res = $this->create_post_as( $this->user_id );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_banned', $res->as_error()->get_error_code() );
	}

	public function test_pending_verification_user_blocked_on_writes(): void {
		update_user_meta( $this->user_id, '_jetonomy_pending_verification', 1 );

		$res = $this->create_post_as( $this->user_id );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_pending_verification', $res->as_error()->get_error_code() );
	}

	public function test_healthy_user_is_not_blocked_by_the_gate(): void {
		// A clean member must pass the account-status gate (status 201 created).
		$res = $this->create_post_as( $this->user_id );
		$this->assertSame( 201, $res->get_status() );
	}
}
