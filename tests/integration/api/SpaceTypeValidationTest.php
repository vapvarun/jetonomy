<?php
/**
 * Space type is validated at the model seam (Basecamp 10148436699).
 *
 * The `type` column is ENUM('forum','qa','ideas','feed'); MySQL silently
 * stores '' for anything else. PATCH {"type":"chat"} used to return 200 and
 * blank the type, and POST {"type":"chat"} silently created a forum.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Space;

class SpaceTypeValidationTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->space_id = (int) Space::create(
			array(
				'title' => 'Type Space',
				'slug'  => 'type-space-' . uniqid(),
				'type'  => 'forum',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_model_refuses_invalid_type_on_create_and_update(): void {
		$created = Space::create(
			array(
				'title' => 'Bad',
				'slug'  => 'bad-' . uniqid(),
				'type'  => 'chat',
			),
			0
		);
		$this->assertWPError( $created );
		$this->assertSame( 'jetonomy_invalid_space_type', $created->get_error_code() );
		$this->assertSame( 400, $created->get_error_data()['status'] );

		$updated = Space::update( $this->space_id, array( 'type' => 'chat' ) );
		$this->assertWPError( $updated );
		$this->assertSame( 'forum', Space::find( $this->space_id )->type, 'Nothing may be stored on a refused update.' );

		$this->assertTrue( Space::update( $this->space_id, array( 'type' => 'feed' ) ) );
		$this->assertSame( 'feed', Space::find( $this->space_id )->type );
	}

	public function test_rest_patch_with_invalid_type_is_400_and_stores_nothing(): void {
		$request = new WP_REST_Request( 'PATCH', '/jetonomy/v1/spaces/' . $this->space_id );
		$request->set_body_params( array( 'type' => 'chat' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'forum', Space::find( $this->space_id )->type );
	}

	public function test_rest_post_with_invalid_type_is_400_not_a_silent_forum(): void {
		$slug    = 'chat-' . uniqid();
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/spaces' );
		$request->set_body_params(
			array(
				'title' => 'Chat',
				'slug'  => $slug,
				'type'  => 'chat',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( Space::find_by_slug( $slug ) );
	}

	public function test_rest_post_with_feed_type_is_stored(): void {
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/spaces' );
		$request->set_body_params(
			array(
				'title' => 'Feed',
				'type'  => 'feed',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'feed', Space::find( (int) $response->get_data()['id'] )->type );
	}
}
