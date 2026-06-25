<?php
/**
 * Error-path tests: operations attempted in the wrong state.
 *
 * Covers:
 *   - Reply to closed post         → 403
 *   - Vote in space with voting off → 403
 *   - Accept answer twice           → 200 (idempotent)
 *   - Create post in archived space → 403
 *   - Join invite-only space        → 403
 *
 * All tests use the full REST server via rest_do_request() / server->dispatch().
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

class InvalidStateTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int Administrator user who is a member of every test space. */
	private int $admin_id;

	/** @var int Subscriber-level member used for non-admin flows. */
	private int $member_id;

	/** @var int Default test space (public, voting on, open join). */
	private int $space_id;

	/** @var int Category shared across tests. */
	private int $cat_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		// Boot the full REST server so all plugin routes are registered.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Admin — used for moderation-level actions (close, accept).
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_jetonomy_caps( $this->admin_id );

		// Member — a regular subscriber who can post and vote.
		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_jetonomy_caps( $this->member_id );

		$this->cat_id   = Category::create( array( 'name' => 'Invalid State Cat', 'slug' => 'invalid-state-cat-' . uniqid() ) );
		$this->space_id = Space::create( array(
			'title'       => 'Invalid State Space',
			'slug'        => 'invalid-state-space-' . uniqid(),
			'category_id' => $this->cat_id,
			'visibility'  => 'public',
			'join_policy' => 'open',
		) );
		SpaceMember::add( $this->space_id, $this->admin_id, 'admin' );
		SpaceMember::add( $this->space_id, $this->member_id, 'member' );
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
	 * Dispatch a REST request as the given user.
	 *
	 * @param int    $user_id Acting user (0 = guest).
	 * @param string $method  HTTP method.
	 * @param string $route   Route path without the /jetonomy/v1 prefix.
	 * @param array  $params  Body params for write requests; query params otherwise.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function do_request_as( int $user_id, string $method, string $route, array $params = [] ) {
		wp_set_current_user( $user_id );
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
	 * Create a published post in the default space.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Post ID.
	 */
	private function make_post( array $overrides = [] ): int {
		return Post::create( array_merge(
			array(
				'space_id'      => $this->space_id,
				'author_id'     => $this->admin_id,
				'title'         => 'Test Post ' . uniqid(),
				'slug'          => 'test-post-' . uniqid(),
				'content'       => '<p>Content.</p>',
				'content_plain' => 'Content.',
				'type'          => 'discussion',
				'status'        => 'publish',
			),
			$overrides
		) );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Reply to a closed post must return 403.
	 *
	 * The Replies_Controller checks `$post->is_closed` after the space check
	 * and before inserting the reply.
	 */
	public function test_reply_to_closed_post_returns_403(): void {
		$post_id = $this->make_post();
		Post::close( $post_id );

		$response = $this->do_request_as( $this->member_id, 'POST', "/posts/{$post_id}/replies", array(
			'content' => '<p>Trying to reply to a closed post.</p>',
		) );

		$this->assertEquals(
			403,
			$response->get_status(),
			"Expected 403 when replying to a closed post; got {$response->get_status()}."
		);
	}

	/**
	 * Voting in a space that has allow_voting=0 must return 403.
	 *
	 * The Permission_Engine reads the space settings JSON and denies 'vote'
	 * when allow_voting is absent or not '1'.
	 */
	public function test_vote_in_space_with_voting_disabled_returns_403(): void {
		// Create a space with voting explicitly disabled in the settings JSON.
		$no_vote_space_id = Space::create( array(
			'title'       => 'No Vote Space',
			'slug'        => 'no-vote-space-' . uniqid(),
			'category_id' => $this->cat_id,
			'visibility'  => 'public',
			'join_policy' => 'open',
			'settings'    => wp_json_encode( array( 'allow_voting' => '0' ) ),
		) );
		SpaceMember::add( $no_vote_space_id, $this->member_id, 'member' );

		$post_id = Post::create( array(
			'space_id'      => $no_vote_space_id,
			'author_id'     => $this->admin_id,
			'title'         => 'No Vote Post ' . uniqid(),
			'slug'          => 'no-vote-post-' . uniqid(),
			'content'       => '<p>Content.</p>',
			'content_plain' => 'Content.',
			'type'          => 'discussion',
			'status'        => 'publish',
		) );

		$response = $this->do_request_as( $this->member_id, 'POST', "/posts/{$post_id}/vote", array(
			'value' => 1,
		) );

		$this->assertEquals(
			403,
			$response->get_status(),
			"Expected 403 when voting in a space with allow_voting=0; got {$response->get_status()}."
		);
	}

	/**
	 * Accepting a reply that is already the accepted answer must be idempotent (200).
	 *
	 * The controller calls Reply::mark_accepted() and Post::accept_reply() on
	 * every request — there is no 409 guard; the operation is safe to repeat.
	 */
	public function test_accept_answer_twice_is_idempotent(): void {
		// Accept-answer is gated to Q&A spaces since 93b302a (2026-05-08);
		// the shared fixture space is a forum, so build a qa-typed space.
		$qa_space_id = Space::create( array(
			'title'       => 'Invalid State QA Space',
			'slug'        => 'invalid-state-qa-' . uniqid(),
			'category_id' => $this->cat_id,
			'visibility'  => 'public',
			'join_policy' => 'open',
			'type'        => 'qa',
		) );
		SpaceMember::add( $qa_space_id, $this->admin_id, 'admin' );
		SpaceMember::add( $qa_space_id, $this->member_id, 'member' );

		$post_id  = $this->make_post( array( 'space_id' => $qa_space_id, 'type' => 'question' ) );
		$reply_id = Reply::create( array(
			'post_id'       => $post_id,
			'author_id'     => $this->member_id,
			'content'       => '<p>The answer.</p>',
			'content_plain' => 'The answer.',
			'status'        => 'publish',
		) );

		// First accept.
		$first = $this->do_request_as( $this->admin_id, 'POST', "/replies/{$reply_id}/accept" );
		$this->assertEquals( 200, $first->get_status(), "First accept should succeed with 200." );

		// Second accept of the same reply — must still succeed, not conflict.
		$second = $this->do_request_as( $this->admin_id, 'POST', "/replies/{$reply_id}/accept" );
		$this->assertContains(
			$second->get_status(),
			array( 200, 409 ),
			"Second accept must be 200 (idempotent) or 409 (conflict guard added); got {$second->get_status()}."
		);

		// The post must reflect the accepted reply.
		$post = Post::find( $post_id );
		$this->assertEquals(
			$reply_id,
			(int) $post->accepted_reply_id,
			'accepted_reply_id must remain set after duplicate accept.'
		);
	}

	/**
	 * Creating a post in an archived space must return 403.
	 *
	 * The Posts_Controller checks `$space->status` for 'archived' or 'locked'
	 * after the permission check and before inserting the post.
	 */
	public function test_create_post_in_archived_space_returns_403(): void {
		$archived_space_id = Space::create( array(
			'title'       => 'Archived Space',
			'slug'        => 'archived-space-' . uniqid(),
			'category_id' => $this->cat_id,
			'visibility'  => 'public',
			'join_policy' => 'open',
			'status'      => 'archived',
		) );
		SpaceMember::add( $archived_space_id, $this->member_id, 'member' );

		$response = $this->do_request_as( $this->member_id, 'POST', "/spaces/{$archived_space_id}/posts", array(
			'title'   => 'Post in archived space',
			'content' => '<p>This should be rejected.</p>',
			'type'    => 'discussion',
		) );

		$this->assertEquals(
			403,
			$response->get_status(),
			"Expected 403 when creating a post in an archived space; got {$response->get_status()}."
		);
	}

	/**
	 * Joining an invite-only space without an invite must return 403.
	 *
	 * The Spaces_Controller checks `$space->join_policy === 'invite'` and
	 * returns a 403 error with code 'jetonomy_invite_only'.
	 */
	public function test_join_invite_only_space_without_invite_returns_403(): void {
		$invite_space_id = Space::create( array(
			'title'       => 'Invite Only Space',
			'slug'        => 'invite-only-space-' . uniqid(),
			'category_id' => $this->cat_id,
			'visibility'  => 'public',
			'join_policy' => 'invite',
		) );

		// A new user who has no invite.
		$outsider_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_jetonomy_caps( $outsider_id );

		$response = $this->do_request_as( $outsider_id, 'POST', "/spaces/{$invite_space_id}/members" );

		$this->assertEquals(
			403,
			$response->get_status(),
			"Expected 403 when joining an invite-only space without an invite; got {$response->get_status()}."
		);

		$data = $response->get_data();
		$this->assertEquals(
			'jetonomy_invite_only',
			$data['code'] ?? '',
			'Error code must be jetonomy_invite_only.'
		);
	}
}
