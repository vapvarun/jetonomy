<?php
/**
 * POST /spaces/{id}/posts honours the client's is_anonymous flag only once an
 * extension turns anonymity on through jetonomy_anonymous_posting_enabled.
 *
 * Free has no anonymity feature of its own, so a client that sends the flag
 * must not get an anonymous topic by itself.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class AnonymousCreateFlagTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat             = Category::create(
			array(
				'name' => 'Anon Cat',
				'slug' => 'anon-cat-' . uniqid(),
			)
		);
		$this->space_id  = Space::create(
			array(
				'title'       => 'Anon Space',
				'slug'        => 'anon-space-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);
		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->space_id, $this->member_id, 'member' );
		wp_set_current_user( $this->member_id );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'jetonomy_anonymous_posting_enabled' );
		parent::tear_down();
	}

	private function create_with_flag(): int {
		$req = new WP_REST_Request( 'POST', '/jetonomy/v1/spaces/' . $this->space_id . '/posts' );
		$req->set_body_params(
			array(
				'title'        => 'Flag topic ' . uniqid(),
				'content'      => '<p>Body.</p>',
				'is_anonymous' => true,
			)
		);
		$res = $this->server->dispatch( $req );
		$this->assertSame( 201, $res->get_status() );
		return (int) $res->get_data()['id'];
	}

	public function test_the_flag_is_ignored_while_no_extension_enables_anonymity(): void {
		$post = Post::find( $this->create_with_flag() );
		$this->assertSame( 0, (int) $post->is_anonymous );
	}

	/** @covers-existing The enabled path already stored the flag; this reaches the filter so the coverage gate can see it. */
	public function test_the_flag_counts_once_an_extension_enables_anonymity(): void {
		add_filter( 'jetonomy_anonymous_posting_enabled', '__return_true' );
		$post = Post::find( $this->create_with_flag() );
		$this->assertSame( 1, (int) $post->is_anonymous );
	}
}
