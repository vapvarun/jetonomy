<?php
/**
 * Integration tests for the Polls Pro extension.
 *
 * Exercises POST /posts/:post_id/poll (create) and
 * POST /polls/:id/vote (vote) via the REST server to verify that:
 * - Polls are created with an options array in the response.
 * - Voting stores the voted option in user_votes.
 *
 * Poll tables are created by calling Extension::activate() in set_up()
 * so the tests are self-contained.
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

class PollsTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	/** @var int Post author, who is also the poll creator. */
	private int $author_id;

	/** @var int Published post that polls will be attached to. */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — polls tests skipped.' );
		}

		Schema::create_tables();

		// Ensure the polls tables exist.
		if ( class_exists( 'Jetonomy_Pro\Extensions\Polls\Extension' ) ) {
			$ext = new \Jetonomy_Pro\Extensions\Polls\Extension();
			$ext->activate();
		}

		// Bootstrap REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Author creates the post, so they have permission to add a poll.
		$this->author_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$cat_id        = Category::create( [ 'name' => 'Poll Cat', 'slug' => 'poll-cat-' . uniqid() ] );
		$space_id      = Space::create( [
			'title'       => 'Poll Space',
			'slug'        => 'poll-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );
		$this->post_id = Post::create( [
			'space_id'  => $space_id,
			'author_id' => $this->author_id,
			'title'     => 'Poll Test Post',
			'slug'      => 'poll-post-' . uniqid(),
			'content'   => '<p>Which option?</p>',
			'status'    => 'publish',
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
	 * Create a poll on $this->post_id as the post author.
	 *
	 * @param array $overrides Override default poll fields.
	 * @return \WP_REST_Response
	 */
	private function create_poll( array $overrides = [] ): \WP_REST_Response {
		wp_set_current_user( $this->author_id );

		$params = array_merge(
			[
				'question' => 'What is your favourite colour?',
				'type'     => 'single',
				'options'  => [ 'Red', 'Green', 'Blue' ],
			],
			$overrides
		);

		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/poll" );
		$request->set_body_params( $params );

		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Creating a poll must return 201 and the response body must include
	 * an "options" key that is a non-empty array.
	 */
	public function test_create_poll_returns_options_array(): void {
		$response = $this->create_poll();

		$this->assertEquals( 201, $response->get_status(),
			'Poll creation must return HTTP 201.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'options', $data,
			'Response must include an "options" key.' );
		$this->assertIsArray( $data['options'],
			'"options" must be an array.' );
		$this->assertNotEmpty( $data['options'],
			'"options" must not be empty.' );
	}

	/**
	 * After voting on a single-choice poll the response must contain a
	 * "user_votes" array that includes the voted option_id.
	 */
	public function test_vote_adds_option_to_user_votes(): void {
		// Create the poll first.
		$create = $this->create_poll();
		$this->assertEquals( 201, $create->get_status(),
			'Poll must be created before voting.' );

		$poll_data = $create->get_data();
		$poll_id   = (int) $poll_data['id'];

		// Pick the first option.
		$option_id = (int) $poll_data['options'][0]['id'];

		// Vote as the author (logged in).
		wp_set_current_user( $this->author_id );
		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/polls/{$poll_id}/vote" );
		$request->set_body_params( [ 'option_id' => $option_id ] );

		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 200, 201 ],
			'Voting must succeed.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'user_votes', $data,
			'Response must include "user_votes" key.' );
		$this->assertContains( $option_id, (array) $data['user_votes'],
			'The voted option_id must appear in user_votes.' );
	}
}
