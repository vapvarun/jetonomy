<?php
/**
 * AI reply suggestions and custom post fields: a topic the caller cannot read
 * answers exactly like a missing one (404, same code and message). A readable
 * topic the caller may not edit keeps its 403.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\DB\Schema;

class ProHiddenTargetTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;
	private int $author_id;
	private int $outsider_id;
	private int $private_post_id;
	private int $public_post_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();

		$fields = new \Jetonomy_Pro\Extensions\Custom_Fields\Extension();
		$fields->activate();
		$fields->boot();

		$ai = new \Jetonomy_Pro\Extensions\Ai\Extension();
		$ai->activate();
		$ai->boot();
		$this->stub_ai( $ai );

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cat     = Category::create( array( 'name' => 'Hidden Target', 'slug' => 'ht-' . uniqid() ) );
		$private = Space::create( array( 'title' => 'Private', 'slug' => 'ht-private-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		$public  = Space::create( array( 'title' => 'Public', 'slug' => 'ht-public-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		SpaceMember::add( $private, $this->author_id, 'member' );

		$this->private_post_id = Post::create( array( 'space_id' => $private, 'author_id' => $this->author_id, 'title' => 'Secret', 'slug' => 'ht-s-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
		$this->public_post_id  = Post::create( array( 'space_id' => $public, 'author_id' => $this->author_id, 'title' => 'Open', 'slug' => 'ht-o-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/** Reply suggestions on, with a client that answers without a network call. */
	private function stub_ai( object $ai ): void {
		$ref = new \ReflectionClass( $ai );

		$settings = $ref->getProperty( 'settings' );
		$settings->setValue( $ai, array( 'features' => array( 'reply_suggestions' => array( 'enabled' => true ) ) ) );

		$client = new class( array(), new \Jetonomy_Pro\Extensions\Ai\Usage_Tracker( array() ) ) extends \Jetonomy_Pro\Extensions\Ai\AI_Client {
			public function is_available(): bool {
				return true;
			}
			public function chat( string $feature, array $messages, array $options = array(), array $context = array() ): array {
				return array( 'content' => wp_json_encode( array( 'reply' => 'Draft.' ) ) );
			}
		};
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $ai, $client );
	}

	private function dispatch( string $method, string $route, array $params, int $user_id, bool $json = false ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		if ( $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		} else {
			$request->set_body_params( $params );
		}
		return $this->server->dispatch( $request );
	}

	public function test_ai_suggest_reply_on_unreadable_post_reads_like_missing(): void {
		$hidden  = $this->dispatch( 'POST', '/jetonomy/v1/ai/suggest-reply', array( 'post_id' => $this->private_post_id ), $this->outsider_id );
		$missing = $this->dispatch( 'POST', '/jetonomy/v1/ai/suggest-reply', array( 'post_id' => 999999 ), $this->outsider_id );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertNotSame( 'rest_no_route', $hidden->get_data()['code'] ?? '' );
		$this->assertSame( $missing->get_data(), $hidden->get_data() );
	}

	/** @covers-existing A readable post still gets a suggestion. */
	public function test_ai_suggest_reply_on_readable_post_succeeds(): void {
		$res = $this->dispatch( 'POST', '/jetonomy/v1/ai/suggest-reply', array( 'post_id' => $this->public_post_id ), $this->outsider_id );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_update_fields_on_unreadable_post_reads_like_missing(): void {
		$hidden  = $this->dispatch( 'PATCH', "/jetonomy/v1/posts/{$this->private_post_id}/fields", array( 'x' => 'y' ), $this->outsider_id, true );
		$missing = $this->dispatch( 'PATCH', '/jetonomy/v1/posts/999999/fields', array( 'x' => 'y' ), $this->outsider_id, true );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertNotSame( 'rest_no_route', $hidden->get_data()['code'] ?? '' );
		$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'] );
		$this->assertSame( $missing->get_data()['message'], $hidden->get_data()['message'] );
	}

	/** @covers-existing Readable but not editable keeps the 403. */
	public function test_update_fields_on_readable_post_of_another_member_is_403(): void {
		$res = $this->dispatch( 'PATCH', "/jetonomy/v1/posts/{$this->public_post_id}/fields", array( 'x' => 'y' ), $this->outsider_id, true );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_forbidden', $res->get_data()['code'] );
	}

	public function test_silenced_author_is_told_why_fields_cannot_be_updated(): void {
		Restriction::ban( $this->author_id, 'silence', $this->admin_id );
		$res = $this->dispatch( 'PATCH', "/jetonomy/v1/posts/{$this->public_post_id}/fields", array( 'x' => 'y' ), $this->author_id, true );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $res->get_data()['code'] );
	}
}
