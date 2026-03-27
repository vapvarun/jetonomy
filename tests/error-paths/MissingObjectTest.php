<?php
/**
 * Error-path tests: operations on deleted or nonexistent objects.
 *
 * All tests use rest_do_request() to exercise the full controller stack,
 * including permission callbacks and object-existence guards.
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

class MissingObjectTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int */
	private int $admin_id;

	/** @var int */
	private int $space_id;

	/**
	 * Bootstrap: tables, REST server, admin user, and a public space.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		// Boot the full REST server so all plugin routes are registered.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Admin user who is also a space member so permission checks pass.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_jetonomy_caps( $this->admin_id );

		$cat_id         = Category::create( array( 'name' => 'Missing Obj Cat', 'slug' => 'missing-obj-cat' ) );
		$this->space_id = Space::create( array(
			'title'       => 'Missing Obj Space',
			'slug'        => 'missing-obj-space',
			'category_id' => $cat_id,
			'visibility'  => 'public',
		) );
		SpaceMember::add( $this->space_id, $this->admin_id, 'admin' );
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
	 * Dispatch a REST request as the admin user and return the response.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Full route path (without /jetonomy/v1 prefix).
	 * @param array  $params Body/query params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function do_request( string $method, string $route, array $params = [] ) {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$request->set_body_params( $params );
		} else {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Create a published post then soft-delete it by trashing it directly,
	 * so we have a post ID that no longer resolves (Post::find checks status).
	 *
	 * Actually, Post::find() returns regardless of status; but the controllers
	 * that accept replies/votes check the post exists. To simulate a truly
	 * absent row we use a large ID that was never inserted.
	 *
	 * For "edit deleted post" we create a real post, capture its ID, then
	 * hard-delete the row from the database so Post::find returns null.
	 *
	 * @return int The now-absent post ID.
	 */
	private function create_then_hard_delete_post(): int {
		$post_id = Post::create( array(
			'space_id'      => $this->space_id,
			'author_id'     => $this->admin_id,
			'title'         => 'To Delete Post ' . uniqid(),
			'slug'          => 'to-delete-' . uniqid(),
			'content'       => '<p>Will be deleted.</p>',
			'content_plain' => 'Will be deleted.',
			'type'          => 'discussion',
			'status'        => 'publish',
		) );

		// Hard-delete the row so the controller gets a genuine not-found.
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'jt_posts', array( 'id' => $post_id ), array( '%d' ) );

		return $post_id;
	}

	/**
	 * Create a published reply then hard-delete it from the database.
	 *
	 * @param int $post_id Parent post ID.
	 * @return int The now-absent reply ID.
	 */
	private function create_then_hard_delete_reply( int $post_id ): int {
		$reply_id = Reply::create( array(
			'post_id'       => $post_id,
			'author_id'     => $this->admin_id,
			'content'       => '<p>Will be deleted.</p>',
			'content_plain' => 'Will be deleted.',
			'status'        => 'publish',
		) );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'jt_replies', array( 'id' => $reply_id ), array( '%d' ) );

		return $reply_id;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * PATCH /posts/{id} on a deleted post must return 404.
	 */
	public function test_edit_deleted_post_returns_404(): void {
		$ghost_id = $this->create_then_hard_delete_post();

		$response = $this->do_request( 'PATCH', "/posts/{$ghost_id}", array(
			'content' => '<p>Updated content.</p>',
		) );

		$this->assertEquals(
			404,
			$response->get_status(),
			"Expected 404 when editing a deleted post; got {$response->get_status()}."
		);
	}

	/**
	 * POST /posts/{id}/replies on a deleted post must return 404.
	 */
	public function test_reply_to_deleted_post_returns_404(): void {
		$ghost_id = $this->create_then_hard_delete_post();

		$response = $this->do_request( 'POST', "/posts/{$ghost_id}/replies", array(
			'content' => '<p>A reply to nothing.</p>',
		) );

		$this->assertEquals(
			404,
			$response->get_status(),
			"Expected 404 when replying to a deleted post; got {$response->get_status()}."
		);
	}

	/**
	 * POST /posts/{id}/vote on a nonexistent post must return 404.
	 *
	 * Uses a large ID that was never inserted (no hard-delete needed).
	 */
	public function test_vote_on_nonexistent_post_returns_404(): void {
		$response = $this->do_request( 'POST', '/posts/999999999/vote', array(
			'value' => 1,
		) );

		$this->assertEquals(
			404,
			$response->get_status(),
			"Expected 404 when voting on a nonexistent post; got {$response->get_status()}."
		);
	}

	/**
	 * POST /replies/{id}/accept on a nonexistent reply must return 404.
	 */
	public function test_accept_answer_on_nonexistent_reply_returns_404(): void {
		$response = $this->do_request( 'POST', '/replies/999999999/accept' );

		$this->assertEquals(
			404,
			$response->get_status(),
			"Expected 404 when accepting a nonexistent reply; got {$response->get_status()}."
		);
	}

	/**
	 * DELETE /replies/{id} on an already-deleted reply (hard-deleted row) must return 404.
	 */
	public function test_delete_already_deleted_reply_returns_404(): void {
		// We need a live parent post so the controller can find it if needed.
		$post_id  = Post::create( array(
			'space_id'      => $this->space_id,
			'author_id'     => $this->admin_id,
			'title'         => 'Parent Post for Delete Test',
			'slug'          => 'parent-post-delete-test-' . uniqid(),
			'content'       => '<p>Parent.</p>',
			'content_plain' => 'Parent.',
			'type'          => 'discussion',
			'status'        => 'publish',
		) );
		$ghost_id = $this->create_then_hard_delete_reply( $post_id );

		$response = $this->do_request( 'DELETE', "/replies/{$ghost_id}" );

		$this->assertEquals(
			404,
			$response->get_status(),
			"Expected 404 when deleting an already-deleted reply; got {$response->get_status()}."
		);
	}

	/**
	 * POST /flags on a nonexistent post must return 400 or 404.
	 *
	 * The moderation controller validates the target object exists; a missing
	 * object should not result in a successful 201.
	 */
	public function test_flag_nonexistent_post_returns_error(): void {
		$response = $this->do_request( 'POST', '/flags', array(
			'object_type' => 'post',
			'object_id'   => 999999999,
			'reason'      => 'spam',
		) );

		$this->assertContains(
			$response->get_status(),
			array( 400, 404 ),
			"Expected 400 or 404 when flagging a nonexistent post; got {$response->get_status()}."
		);
	}
}
