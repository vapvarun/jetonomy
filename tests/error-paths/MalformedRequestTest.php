<?php
/**
 * Error-path tests: malformed or incomplete request bodies.
 *
 * Each test sends a request that is missing a required field or supplies an
 * out-of-range value, then asserts the controller rejects it with 400.
 *
 * The REST framework enforces 'required' args at the route level, so most of
 * these tests never even reach the controller callback — they are rejected by
 * WP_REST_Request::validate_params() before the callback fires.
 *
 * @package Jetonomy\Tests\ErrorPaths
 */

namespace Jetonomy\Tests\ErrorPaths;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

class MalformedRequestTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int Administrator used for all requests. */
	private int $admin_id;

	/** @var int A live space every test can post into. */
	private int $space_id;

	/** @var int A live post every reply/vote test can use. */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		// Boot the full REST server so all plugin routes are registered.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_jetonomy_caps( $this->admin_id );

		$cat_id         = Category::create( array( 'name' => 'Malformed Cat', 'slug' => 'malformed-cat' ) );
		$this->space_id = Space::create( array(
			'title'       => 'Malformed Request Space',
			'slug'        => 'malformed-request-space',
			'category_id' => $cat_id,
			'visibility'  => 'public',
			'join_policy' => 'open',
		) );
		SpaceMember::add( $this->space_id, $this->admin_id, 'admin' );

		$this->post_id = Post::create( array(
			'space_id'      => $this->space_id,
			'author_id'     => $this->admin_id,
			'title'         => 'Malformed Test Post',
			'slug'          => 'malformed-test-post',
			'content'       => '<p>Content.</p>',
			'content_plain' => 'Content.',
			'type'          => 'discussion',
			'status'        => 'publish',
		) );
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
	 * Grant the standard Jetonomy capabilities to a user.
	 *
	 * @param int $user_id
	 */
	private function grant_jetonomy_caps( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		$caps = array(
			'jetonomy_read',
			'jetonomy_create_posts',
			'jetonomy_create_replies',
			'jetonomy_vote',
			'jetonomy_flag',
			'jetonomy_close_posts',
			'jetonomy_edit_others_posts',
			'jetonomy_delete_others_posts',
			'jetonomy_pin_posts',
		);
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
	}

	/**
	 * Dispatch a POST REST request as the admin user.
	 *
	 * @param string $route  Route without the /jetonomy/v1 prefix.
	 * @param array  $params Body parameters.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function post_as_admin( string $route, array $params = [] ) {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1' . $route );
		$request->set_body_params( $params );
		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * POST /spaces/{id}/posts without a title must return 400.
	 *
	 * 'title' is declared required in get_create_args(); the REST framework
	 * rejects the request before the callback fires.
	 */
	public function test_create_post_without_title_returns_400(): void {
		$response = $this->post_as_admin( "/spaces/{$this->space_id}/posts", array(
			// 'title' intentionally omitted.
			'content' => '<p>Content without a title.</p>',
			'type'    => 'discussion',
		) );

		$this->assertEquals(
			400,
			$response->get_status(),
			"Expected 400 when creating a post without a title; got {$response->get_status()}."
		);
	}

	/**
	 * POST /posts/{id}/replies without content must return 400.
	 *
	 * 'content' is declared required in Replies_Controller::get_create_args().
	 */
	public function test_create_reply_without_content_returns_400(): void {
		$response = $this->post_as_admin( "/posts/{$this->post_id}/replies", array(
			// 'content' intentionally omitted.
		) );

		$this->assertEquals(
			400,
			$response->get_status(),
			"Expected 400 when creating a reply without content; got {$response->get_status()}."
		);
	}

	/**
	 * POST /flags without the required object_type must return 400.
	 *
	 * 'object_type' is a required, enumerated arg in Moderation_Controller.
	 */
	public function test_create_flag_without_object_type_returns_400(): void {
		$response = $this->post_as_admin( '/flags', array(
			// 'object_type' intentionally omitted.
			'object_id' => $this->post_id,
			'reason'    => 'spam',
		) );

		$this->assertEquals(
			400,
			$response->get_status(),
			"Expected 400 when flagging without object_type; got {$response->get_status()}."
		);
	}

	/**
	 * POST /subscriptions without the required object_id must return 400.
	 *
	 * 'object_id' is a required arg in Subscriptions_Controller::get_create_args().
	 * The controller also validates it is non-zero, so even sending 0 is rejected.
	 */
	public function test_create_subscription_without_object_id_returns_400(): void {
		$response = $this->post_as_admin( '/subscriptions', array(
			'object_type' => 'post',
			// 'object_id' intentionally omitted.
		) );

		$this->assertEquals(
			400,
			$response->get_status(),
			"Expected 400 when subscribing without object_id; got {$response->get_status()}."
		);
	}

	/**
	 * POST /posts/{id}/vote with value=99 must return 400.
	 *
	 * The votes endpoint declares 'value' with enum=[1,-1]. Any other integer
	 * is rejected at the REST argument validation layer before the callback.
	 */
	public function test_vote_with_out_of_range_value_returns_400(): void {
		$response = $this->post_as_admin( "/posts/{$this->post_id}/vote", array(
			'value' => 99,
		) );

		$this->assertEquals(
			400,
			$response->get_status(),
			"Expected 400 when voting with value=99 (not 1 or -1); got {$response->get_status()}."
		);
	}
}
