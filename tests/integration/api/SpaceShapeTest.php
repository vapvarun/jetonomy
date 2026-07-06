<?php
/**
 * Integration test: prepare_space() membership context (1.6.0 mobile API).
 *
 * GET /spaces/{id} must expose viewer-relative is_member / viewer_role /
 * is_subscribed, additive and null-safe for logged-out callers.
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

class SpaceShapeTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat            = Category::create(
			array(
				'name' => 'Shape Cat',
				'slug' => 'shape-cat-' . uniqid(),
			)
		);
		$this->space_id = Space::create(
			array(
				'title'       => 'Shape Space',
				'slug'        => 'shape-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);

		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->space_id, $this->member_id, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_adds_membership_context_for_member(): void {
		wp_set_current_user( $this->member_id );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/spaces/{$this->space_id}" ) )->get_data();

		foreach ( array( 'is_member', 'viewer_role', 'is_subscribed' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertTrue( $data['is_member'] );
		$this->assertSame( 'member', $data['viewer_role'] );
	}

	public function test_safe_defaults_for_logged_out_viewer(): void {
		wp_set_current_user( 0 );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', "/jetonomy/v1/spaces/{$this->space_id}" ) )->get_data();

		$this->assertFalse( $data['is_member'] );
		$this->assertNull( $data['viewer_role'] );
		$this->assertFalse( $data['is_subscribed'] );
	}
}
