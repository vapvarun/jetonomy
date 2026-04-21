<?php
/**
 * Integration test: spaces filtered by `postable_by_me=1`.
 *
 * Filter semantics: returns spaces where the current user is a member AND
 * the permission engine says they can create_posts. Logged-out callers
 * receive an empty array (never 401 — this is a UI-hint endpoint).
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;
use Jetonomy\API\Spaces_Controller;

class PostableByMeTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	private int $category_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook.
		do_action( 'rest_api_init' );

		( new Spaces_Controller() )->register_routes();

		$this->category_id = Category::create(
			array(
				'name' => 'Postable Test',
				'slug' => 'postable-test-' . uniqid(),
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make_space( string $slug_suffix, string $visibility = 'public' ): int {
		return Space::create(
			array(
				'title'       => ucfirst( $slug_suffix ),
				'slug'        => 'ptest-' . $slug_suffix . '-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => $visibility,
			)
		);
	}

	private function list_postable(): array {
		$req = new WP_REST_Request( 'GET', '/jetonomy/v1/spaces' );
		$req->set_param( 'postable_by_me', 1 );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		return (array) $res->get_data();
	}

	public function test_logged_out_returns_empty_array(): void {
		$this->make_space( 'pub' );

		wp_set_current_user( 0 );
		$data = $this->list_postable();

		$this->assertSame( array(), $data, 'Logged-out callers must see zero postable spaces.' );
	}

	public function test_returns_only_spaces_user_is_member_of(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $user_id );

		$joined     = $this->make_space( 'joined' );
		$not_joined = $this->make_space( 'not-joined' );

		SpaceMember::add( $joined, $user_id, 'member' );

		wp_set_current_user( $user_id );
		$data = $this->list_postable();

		$ids = array_map( static fn ( $s ) => (int) ( is_array( $s ) ? $s['id'] : $s->id ), $data );
		$this->assertContains( $joined, $ids, 'Joined space must appear in postable_by_me list.' );
		$this->assertNotContains( $not_joined, $ids, 'Non-member public space must NOT appear — filter is member-scoped.' );
	}

	public function test_filter_is_off_by_default(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $user_id );

		$space = $this->make_space( 'default-off' );

		wp_set_current_user( $user_id );

		$req = new WP_REST_Request( 'GET', '/jetonomy/v1/spaces' );
		// No postable_by_me param.
		$res = $this->server->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$data = (array) $res->get_data();
		$ids  = array_map( static fn ( $s ) => (int) ( is_array( $s ) ? $s['id'] : $s->id ), $data );

		$this->assertContains( $space, $ids, 'Without postable_by_me, all visible spaces are returned (existing behaviour preserved).' );
	}
}
