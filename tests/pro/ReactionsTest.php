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
	 * Count the reaction hooks, and play the other request: $other runs once, the moment the request under test makes its
	 * first query that matches $when (after it has read the existing state, before it writes).
	 *
	 * @return array<string,int> Times each hook fired.
	 */
	private function with_parallel_request( string $when, callable $other, callable $run ): array {
		$fired = [ 'toggled' => 0, 'first' => 0 ];
		add_action( 'jetonomy_pro_reaction_toggled', function () use ( &$fired ): void { ++$fired['toggled']; } );
		add_action( 'jetonomy_pro_first_reaction', function () use ( &$fired ): void { ++$fired['first']; } );
		$done = false;
		add_filter( 'query', static function ( $sql ) use ( &$done, $when, $other ) {
			if ( ! $done && 1 === preg_match( $when, $sql ) ) {
				$done = true;
				$other();
			}
			return $sql;
		} );
		$run();
		return $fired;
	}

	private function reaction_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_reactions WHERE user_id = %d AND object_id = %d", $this->user_id, $this->post_id ) );
	}

	/**
	 * Twenty parallel first reactions from one member left one row but fired the toggled hook 20 times and told the author
	 * up to 16 times that their post got its first reaction.
	 */
	public function test_a_parallel_first_reaction_fires_nothing_and_notifies_nobody(): void {
		global $wpdb;
		$response = null;
		$fired    = $this->with_parallel_request(
			'/SELECT COUNT\(\*\) FROM .*jt_pro_reactions/',
			function () use ( $wpdb ): void {
				$wpdb->insert( $wpdb->prefix . 'jt_pro_reactions', [ 'user_id' => $this->user_id, 'object_type' => 'post', 'object_id' => $this->post_id, 'emoji' => 'thumbsup', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
			},
			function () use ( &$response ): void {
				$response = $this->toggle( 'thumbsup', $this->user_id );
			}
		);

		$this->assertSame( 1, $this->reaction_rows() );
		$this->assertSame( 0, $fired['toggled'], 'The request that stored the row fires the hook, not this one.' );
		$this->assertSame( 0, $fired['first'], 'And it is the one that tells the author.' );
		$this->assertSame( 'added', $response->get_data()['action'], 'The page still shows the reaction the member has.' );
	}

	public function test_a_parallel_remove_fires_once(): void {
		global $wpdb;
		$this->toggle( 'thumbsup', $this->user_id );
		$response = null;
		$fired    = $this->with_parallel_request(
			'/^\s*DELETE FROM .*jt_pro_reactions/',
			function () use ( $wpdb ): void {
				$wpdb->delete( $wpdb->prefix . 'jt_pro_reactions', [ 'user_id' => $this->user_id, 'object_id' => $this->post_id ] );
			},
			function () use ( &$response ): void {
				$response = $this->toggle( 'thumbsup', $this->user_id );
			}
		);

		$this->assertSame( 0, $this->reaction_rows() );
		$this->assertSame( 0, $fired['toggled'] );
		$this->assertSame( 'removed', $response->get_data()['action'] );
	}

	public function test_without_a_parallel_request_add_swap_and_remove_each_fire_once(): void {
		$fired = [ 'toggled' => 0, 'first' => 0 ];
		add_action( 'jetonomy_pro_reaction_toggled', function () use ( &$fired ): void { ++$fired['toggled']; } );
		add_action( 'jetonomy_pro_first_reaction', function () use ( &$fired ): void { ++$fired['first']; } );

		$this->assertSame( 'added', $this->toggle( 'thumbsup', $this->user_id )->get_data()['action'] );
		$this->assertSame( 'added', $this->toggle( 'heart', $this->user_id )->get_data()['action'], 'A different emoji swaps the reaction.' );
		$this->assertSame( 1, $this->reaction_rows(), 'Still one reaction per member.' );
		$this->assertSame( 'removed', $this->toggle( 'heart', $this->user_id )->get_data()['action'] );

		$this->assertSame( 3, $fired['toggled'] );
		$this->assertSame( 1, $fired['first'], 'Only the first reaction is announced to the author, not the swap.' );
	}

	/**
	 * A topic in a hidden space answers exactly like a missing one, for reading
	 * and for reacting; a 403 there used to confirm the topic exists.
	 */
	public function test_a_hidden_topic_answers_like_a_missing_one(): void {
		$hidden = Space::create( [ 'title' => 'Hidden', 'slug' => 'hidden-' . uniqid(), 'category_id' => Category::create( [ 'name' => 'H', 'slug' => 'h-' . uniqid() ] ), 'visibility' => 'hidden' ] );
		$post   = (int) Post::create( [ 'space_id' => $hidden, 'title' => 'Secret', 'slug' => 'secret-' . uniqid(), 'content' => 'x', 'status' => 'publish' ] );
		wp_set_current_user( $this->user_id );

		foreach ( [ 'GET', 'POST' ] as $method ) {
			$answers = [];
			foreach ( [ $post, 999999 ] as $id ) {
				$request = new WP_REST_Request( $method, "/jetonomy/v1/posts/{$id}/reactions" );
				$request->set_body_params( [ 'emoji' => 'thumbsup' ] );
				$res       = $this->server->dispatch( $request );
				$answers[] = [ $res->get_status(), $res->get_data()['code'] ?? '' ];
			}
			$this->assertSame( 404, $answers[0][0], "{$method}: hidden topic is not found" );
			$this->assertSame( $answers[1], $answers[0], "{$method}: same answer as a missing id" );
		}
	}

	/**
	 * Asking for one reaction names who chose it, as one translated sentence;
	 * past ten names the rest are counted.
	 */
	public function test_get_with_emoji_names_who_reacted(): void {
		$users = [];
		foreach ( range( 1, 12 ) as $i ) {
			$id = $this->factory()->user->create( [ 'role' => 'subscriber', 'display_name' => "Reactor {$i}" ] );
			get_user_by( 'id', $id )->add_cap( 'jetonomy_vote' );
			$this->toggle( 'thumbsup', $id );
			$users[] = $id;
		}

		$get = function ( string $emoji ): array {
			$request = new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}/reactions" );
			$request->set_param( 'emoji', $emoji );
			return $this->server->dispatch( $request )->get_data();
		};

		$data = $get( 'thumbsup' );
		$this->assertSame( 12, $data['reactors']['total'] );
		$this->assertCount( 10, $data['reactors']['names'] );
		$this->assertStringEndsWith( 'and 2 others', $data['reactors']['label'] );

		$this->assertArrayNotHasKey( 'reactors', $get( 'heart' ), 'no one chose heart' );
	}

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

	/**
	 * A visitor sees counts and a "Log in to react" link, never a picker or a
	 * button that only bounces them to the login screen.
	 */
	public function test_guest_sees_static_counts_and_a_login_link(): void {
		$this->toggle( 'thumbsup', $this->user_id );
		wp_set_current_user( 0 );

		$ext = new \Jetonomy_Pro\Extensions\Reactions\Extension();
		ob_start();
		$ext->render_post_reactions( (object) array( 'id' => $this->post_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Log in to react', $html );
		$this->assertStringContainsString( 'jt-reaction-chip is-static', $html );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringNotContainsString( 'jt-reaction-picker', $html );
	}

	/**
	 * A trashed topic answers 409 (not live), the same as a vote, not the 404 a
	 * reader gets for something they may not see. Nothing is stored.
	 */
	public function test_trashed_target_is_refused_as_not_live(): void {
		Post::update( $this->post_id, [ 'status' => 'trash' ] );
		$response = $this->toggle( 'thumbsup', $this->user_id );
		$this->assertSame( 409, $response->get_status() );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_reactions WHERE object_id = %d", $this->post_id ) ) );
	}

	/**
	 * Reacting is vote-grade: a member a Read access rule admits to a private
	 * space can read and report, but not react, the same as /vote refuses them.
	 */
	public function test_read_rule_viewer_cannot_react(): void {
		$space = (int) Space::create( [ 'title' => 'Advisors', 'slug' => 'adv-' . uniqid(), 'visibility' => 'private' ] );
		\Jetonomy\Models\AccessRule::create( [ 'space_id' => $space, 'rule_type' => 'role', 'rule_value' => 'subscriber', 'grants' => 'read' ] );
		$post = (int) Post::create( [ 'space_id' => $space, 'title' => 'Roadmap notes', 'slug' => 'rm-' . uniqid(), 'content' => '<p>Notes.</p>', 'status' => 'publish' ] );
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$post}/reactions" );
		$request->set_body_params( [ 'emoji' => 'thumbsup' ] );
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
	}
}
