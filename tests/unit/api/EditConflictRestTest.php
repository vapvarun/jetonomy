<?php
/**
 * Two tabs editing one topic or reply: the second save must not silently
 * overwrite the first (wave-2 walk, post-edit). The edit form carries the
 * edit_token it loaded with; a save whose token no longer matches is refused.
 *
 * @package Jetonomy\Tests\Unit\API
 */

namespace Jetonomy\Tests\Unit\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;

class EditConflictRestTest extends WP_UnitTestCase {

	private int $space_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$suffix         = uniqid( 'ec_', true );
		$cat            = (int) Category::create(
			[
				'name' => 'Edit conflict',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id = (int) Space::create(
			[
				'category_id' => $cat,
				'title'       => 'Edit conflict space',
				'slug'        => 's-' . $suffix,
				'visibility'  => 'public',
			],
			0
		);

		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		UserProfile::find_or_create( $this->author_id );
		SpaceMember::add( $this->space_id, $this->author_id, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** @return array{0:int,1:mixed} Status and response data. */
	private function call( string $method, string $route, array $params = [] ): array {
		wp_set_current_user( $this->author_id );
		$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = rest_do_request( $req );
		return [ $res->get_status(), $res->get_data() ];
	}

	public function test_a_stale_topic_edit_is_refused_and_a_fresh_one_saves(): void {
		$post_id = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Original',
				'content'   => '<p>body</p>',
				'status'    => 'publish',
			]
		);

		$loaded = $this->call( 'GET', "/posts/{$post_id}" )[1]['edit_token'];
		$this->assertNotEmpty( $loaded );

		[ $status, $first ] = $this->call( 'PATCH', "/posts/{$post_id}", [ 'title' => 'Tab one', 'edit_token' => $loaded ] );
		$this->assertSame( 200, $status );
		$this->assertNotSame( $loaded, $first['edit_token'], 'the save hands back the new token' );

		[ $status, $second ] = $this->call( 'PATCH', "/posts/{$post_id}", [ 'title' => 'Tab two', 'edit_token' => $loaded ] );
		$this->assertSame( 409, $status );
		$this->assertSame( 'jetonomy_edit_conflict', $second['code'] );
		$this->assertSame( 'Tab one', Post::find( $post_id )->title, 'the first save survives' );

		$this->assertSame( 200, $this->call( 'PATCH', "/posts/{$post_id}", [ 'title' => 'Tab one again', 'edit_token' => $first['edit_token'] ] )[0] );
	}

	public function test_a_stale_reply_edit_is_refused(): void {
		$post_id  = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Thread',
				'content'   => '<p>body</p>',
				'status'    => 'publish',
			]
		);
		$reply_id = (int) Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => '<p>first</p>',
				'status'    => 'publish',
			]
		);
		$loaded   = Reply::edit_token( Reply::find( $reply_id ), Reply::EDIT_FIELDS );

		$this->assertSame( 200, $this->call( 'PATCH', "/replies/{$reply_id}", [ 'content' => '<p>tab one</p>', 'edit_token' => $loaded ] )[0] );

		[ $status, $data ] = $this->call( 'PATCH', "/replies/{$reply_id}", [ 'content' => '<p>tab two</p>', 'edit_token' => $loaded ] );
		$this->assertSame( 409, $status );
		$this->assertSame( 'jetonomy_edit_conflict', $data['code'] );
		$this->assertStringContainsString( 'tab one', Reply::find( $reply_id )->content );
	}

	/** @covers-existing API clients that send no token keep saving as before. */
	public function test_an_edit_without_a_token_is_not_checked(): void {
		$post_id = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'No token',
				'content'   => '<p>body</p>',
				'status'    => 'publish',
			]
		);
		$this->assertSame( 200, $this->call( 'PATCH', "/posts/{$post_id}", [ 'title' => 'Still saves' ] )[0] );
	}
}
