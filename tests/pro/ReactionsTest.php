<?php
/**
 * Integration tests for the Reactions Pro extension.
 *
 * Exercises POST /posts/:id/reactions via the REST server to verify
 * toggle-on, toggle-off, and guest-rejection behaviour. The reactions
 * table is created by calling Extension::activate() in set_up() so
 * the tests are self-contained.
 *
 * Skipped automatically when Jetonomy Pro is not active.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;

class ReactionsTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int */
	private int $user_id;

	/** @var int */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — reactions tests skipped.' );
		}

		Schema::create_tables();

		// Enable the extension and fake a valid lifetime license so boot() runs.
		update_option( 'jetonomy_pro_extensions', [ 'private-messaging', 'reactions', 'polls', 'analytics' ] );
		update_option( 'jetonomy_pro_license', [
			'key'        => 'test-key',
			'status'     => 'valid',
			'expires'    => 'lifetime',
			'tier'       => 'lifetime',
			'item_name'  => 'Jetonomy Pro',
			'checked_at' => current_time( 'mysql', true ),
		] );

		// Ensure the reactions table exists and boot the extension so
		// REST routes are registered before rest_api_init fires.
		if ( class_exists( 'Jetonomy_Pro\Extensions\Reactions\Extension' ) ) {
			$ext = new \Jetonomy_Pro\Extensions\Reactions\Extension();
			$ext->activate();
			$ext->boot();
		}

		// Bootstrap REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create a user with the jetonomy_vote capability.
		$this->user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$user          = get_user_by( 'id', $this->user_id );
		$user->add_cap( 'jetonomy_vote' );

		// Create a published post to react on.
		$cat_id         = Category::create( [ 'name' => 'React Cat', 'slug' => 'react-cat-' . uniqid() ] );
		$space_id       = Space::create( [
			'title'       => 'React Space',
			'slug'        => 'react-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );
		$this->post_id  = Post::create( [
			'space_id' => $space_id,
			'title'    => 'React Test Post',
			'slug'     => 'react-post-' . uniqid(),
			'content'  => '<p>x</p>',
			'status'   => 'publish',
		] );
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
	 * Toggle a reaction on the test post.
	 *
	 * @param string $emoji   A valid emoji slug (e.g. 'thumbsup').
	 * @param int    $user_id User to authenticate as.
	 * @return \WP_REST_Response
	 */
	private function toggle( string $emoji, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/reactions" );
		$request->set_body_params( [ 'emoji' => $emoji ] );

		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * The first toggle for a user on a post must respond with action=added.
	 */
	public function test_toggle_reaction_on_returns_action_added(): void {
		$response = $this->toggle( 'thumbsup', $this->user_id );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'action', $data );
		$this->assertEquals( 'added', $data['action'],
			'First reaction toggle must return action=added.' );
	}

	/**
	 * Toggling the same emoji twice must return action=removed on the
	 * second call, indicating the reaction was withdrawn.
	 */
	public function test_toggle_same_reaction_twice_returns_action_removed(): void {
		// First toggle — adds the reaction.
		$this->toggle( 'thumbsup', $this->user_id );

		// Second toggle — removes it.
		$response = $this->toggle( 'thumbsup', $this->user_id );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'action', $data );
		$this->assertEquals( 'removed', $data['action'],
			'Second toggle of the same reaction must return action=removed.' );
	}

	/**
	 * A guest (user_id = 0) must receive 401 when attempting to react,
	 * because the can_react permission callback requires is_user_logged_in().
	 */
	public function test_guest_cannot_react_receives_401(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/reactions" );
		$request->set_body_params( [ 'emoji' => 'thumbsup' ] );

		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ],
			'Unauthenticated request must be rejected with 401 or 403.' );
	}
}
