<?php
namespace Jetonomy\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * Markup with no text ("<p> </p>", "<p>&nbsp;</p>") is refused like an empty
 * body, on create and on edit, for topics and replies. An image alone is a body.
 */
class BlankContentRefusedTest extends WP_UnitTestCase {

	public function test_blank_helper(): void {
		$this->assertTrue( jetonomy_content_is_blank( '' ) );
		$this->assertTrue( jetonomy_content_is_blank( '<p> </p>' ) );
		$this->assertTrue( jetonomy_content_is_blank( '<p>&nbsp;</p><p><br></p>' ) );
		$this->assertFalse( jetonomy_content_is_blank( '<p>Hi</p>' ) );
		$this->assertFalse( jetonomy_content_is_blank( '<p><img src="https://example.test/a.png" alt=""></p>' ) );
	}

	public function test_rest_refuses_blank_bodies(): void {
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$space  = (int) Space::create( array( 'title' => 'Workshop', 'slug' => 'ws-' . uniqid(), 'visibility' => 'public' ), $admin );
		SpaceMember::add( $space, $member, 'member' );
		$post  = (int) Post::create( array( 'space_id' => $space, 'author_id' => $member, 'title' => 'Bench vise advice', 'slug' => 'vise-' . uniqid(), 'content' => '<p>Which vise?</p>', 'status' => 'publish' ) );
		$reply = (int) Reply::create( array( 'post_id' => $post, 'author_id' => $member, 'content' => '<p>A Record No. 1.</p>' ) );
		wp_set_current_user( $member );

		$calls = array(
			array( 'POST', "/jetonomy/v1/posts/{$post}/replies" ),
			array( 'PATCH', "/jetonomy/v1/replies/{$reply}" ),
			array( 'PATCH', "/jetonomy/v1/posts/{$post}" ),
		);
		foreach ( $calls as list( $method, $route ) ) {
			$req = new WP_REST_Request( $method, $route );
			$req->set_body_params( array( 'content' => '<p>&nbsp;</p>' ) );
			$this->assertSame( 400, rest_do_request( $req )->get_status(), "$method $route" );
		}

		$req = new WP_REST_Request( 'POST', "/jetonomy/v1/spaces/{$space}/posts" );
		$req->set_body_params( array( 'title' => 'Shop lighting', 'content' => '<p> </p>' ) );
		$this->assertSame( 400, rest_do_request( $req )->get_status(), 'new topic' );

		$this->assertSame( '<p>Which vise?</p>', Post::find( $post )->content );
		$this->assertSame( '<p>A Record No. 1.</p>', Reply::find( $reply )->content );
		$this->assertSame( 1, (int) Reply::count_by_post( $post ) );
	}
}
