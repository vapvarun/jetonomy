<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

/**
 * An anonymous post or reply its author edited must not name that author
 * through edited_by, while author_id is masked to 0 (2.0.7 walk
 * c-admin-plugin-pages.23). A moderator's edit keeps naming the moderator.
 */
class AnonymousEditorMaskTest extends WP_UnitTestCase {

	private int $author;
	private int $post;
	private int $reply;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server, $wpdb;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->author = self::factory()->user->create();
		$cat          = Category::create( array( 'name' => 'AE', 'slug' => 'ae-' . uniqid() ) );
		$space        = Space::create( array( 'title' => 'AE', 'slug' => 'ae-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public', 'join_policy' => 'open' ) );
		$this->post   = Post::create( array( 'space_id' => $space, 'author_id' => $this->author, 'title' => 'Secret', 'content' => 'x', 'status' => 'publish' ) );
		$this->reply  = Reply::create( array( 'post_id' => $this->post, 'author_id' => $this->author, 'content' => 'y', 'status' => 'publish' ) );

		$edited = array( 'is_anonymous' => 1, 'edited_by' => $this->author, 'edited_at' => current_time( 'mysql', true ) );
		$wpdb->update( $wpdb->prefix . 'jt_posts', $edited, array( 'id' => $this->post ) );
		$wpdb->update( $wpdb->prefix . 'jt_replies', $edited, array( 'id' => $this->reply ) );
		wp_cache_flush();

		wp_set_current_user( self::factory()->user->create() );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function get( string $route ): array {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1' . $route ) )->get_data();
		return is_array( $data ) ? $data : (array) $data;
	}

	public function test_author_edit_of_anonymous_post_and_reply_does_not_name_the_author(): void {
		$post = $this->get( "/posts/{$this->post}" );
		$this->assertSame( 0, $post['author_id'] );
		$this->assertNull( $post['edited_by'], 'post edited_by leaks the anonymous author' );

		$reply = $this->get( "/replies/{$this->reply}" );
		$this->assertSame( 0, $reply['author_id'] );
		$this->assertNull( $reply['edited_by'], 'reply edited_by leaks the anonymous author' );
	}

	public function test_moderator_edit_still_names_the_moderator(): void {
		global $wpdb;
		$mod = self::factory()->user->create();
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'edited_by' => $mod ), array( 'id' => $this->post ) );
		wp_cache_flush();

		$this->assertSame( $mod, $this->get( "/posts/{$this->post}" )['edited_by'] );
	}
}
