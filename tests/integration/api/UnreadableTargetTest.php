<?php
namespace Jetonomy\Tests\Integration\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Bookmark;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Subscription;

/**
 * A member learns nothing about a topic they may not read.
 *
 * Before 2.0.3, bookmark, follow and vote answered "trashed" or "not published"
 * (409) for a topic in a hidden space, and bookmark and follow wrote a row for
 * it. Space subscribers were notified of new topics with no read check, so
 * following a hidden space by id, or keeping a follow after leaving a private
 * space, delivered its topic titles; a private topic went to every subscriber.
 */
class UnreadableTargetTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $member;
	private int $cat;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->cat    = Category::create( array( 'name' => 'U', 'slug' => 'u-' . uniqid() ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function space( string $visibility ): int {
		return Space::create( array( 'title' => $visibility, 'slug' => $visibility . '-' . uniqid(), 'category_id' => $this->cat, 'visibility' => $visibility ) );
	}

	private function topic( int $space, string $status = 'publish', int $private = 0 ): int {
		return (int) Post::create( array( 'space_id' => $space, 'author_id' => self::factory()->user->create(), 'title' => 'T ' . uniqid(), 'content' => 'Body', 'status' => $status, 'is_private' => $private ) );
	}

	private function call( string $method, string $route, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $this->member );
		$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->server->dispatch( $req );
	}

	public function test_a_hidden_trashed_topic_answers_like_a_missing_one(): void {
		$hidden  = $this->topic( $this->space( 'hidden' ), 'trash' );
		$missing = 999999;

		foreach ( array(
			'bookmark' => fn( $id ) => $this->call( 'POST', '/bookmarks', array( 'post_id' => $id ) ),
			'follow'   => fn( $id ) => $this->call( 'POST', '/subscriptions', array( 'object_type' => 'post', 'object_id' => $id ) ),
		) as $what => $send ) {
			$a = $send( $hidden );
			$b = $send( $missing );
			$this->assertSame( 404, $a->get_status(), "{$what}: a hidden topic is not found" );
			$this->assertSame( array( $b->get_status(), $b->get_data()['code'] ?? '' ), array( $a->get_status(), $a->get_data()['code'] ?? '' ), "{$what}: same answer as a missing id" );
		}
		$this->assertFalse( Bookmark::is_bookmarked( $this->member, $hidden ) );
		$this->assertNull( Subscription::find_for( $this->member, 'post', $hidden ) );
		$this->assertNull( Subscription::find_for( $this->member, 'post', $missing ), 'no row for an id that does not exist either' );

		$vote = $this->call( 'POST', "/posts/{$hidden}/vote", array( 'value' => 1 ) );
		$this->assertSame( 404, $vote->get_status(), 'vote: a hidden topic is not found' );
	}

	public function test_a_hidden_space_cannot_be_followed_by_id(): void {
		$res = $this->call( 'POST', '/subscriptions', array( 'object_type' => 'space', 'object_id' => $this->space( 'hidden' ) ) );
		$this->assertSame( 404, $res->get_status() );
	}

	public function test_a_readable_trashed_topic_still_says_it_is_trashed(): void {
		$res = $this->call( 'POST', '/bookmarks', array( 'post_id' => $this->topic( $this->space( 'public' ), 'trash' ) ) );
		$this->assertSame( 409, $res->get_status(), 'the control case: a public trashed topic keeps its 409' );
	}

	public function test_subscribers_who_cannot_read_a_new_topic_are_not_told(): void {
		global $wpdb;
		$private_space = $this->space( 'private' );
		$left          = self::factory()->user->create();
		$staying       = self::factory()->user->create();
		SpaceMember::add( $private_space, $staying, 'member' );
		Subscription::subscribe( $left, 'space', $private_space ); // Followed, then left the space.
		Subscription::subscribe( $staying, 'space', $private_space );

		$post = $this->topic( $private_space );
		do_action( 'jetonomy_after_create_post', $post, $private_space, null );
		$told = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}jt_notifications WHERE object_type = 'post' AND object_id = %d", $post ) ) );
		$this->assertContains( $staying, $told );
		$this->assertNotContains( $left, $told, 'a former member hears nothing of a private space' );

		$public_space = $this->space( 'public' );
		$mod          = self::factory()->user->create();
		SpaceMember::add( $public_space, $mod, 'moderator' );
		Subscription::subscribe( $this->member, 'space', $public_space );
		Subscription::subscribe( $mod, 'space', $public_space );
		$private_topic = $this->topic( $public_space, 'publish', 1 );
		do_action( 'jetonomy_after_create_post', $private_topic, $public_space, null );
		$told = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}jt_notifications WHERE object_type = 'post' AND object_id = %d", $private_topic ) ) );
		$this->assertContains( $mod, $told, 'space staff may read a private topic' );
		$this->assertNotContains( $this->member, $told, 'a plain subscriber is not sent a private topic' );
	}
}
