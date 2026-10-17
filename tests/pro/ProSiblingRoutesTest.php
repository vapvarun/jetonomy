<?php
/**
 * The same rules as the poll / messaging fixes, on the sibling Pro routes:
 * - reactions, attachments and site announcements refuse a banned or silenced
 *   member with Free's wording, and answer an unreadable target like a missing one;
 * - enum arguments are checked by WordPress (400 rest_invalid_param);
 * - admin-only routes refuse a signed-in member with jetonomy_forbidden;
 * - an unusable custom AI usage range is a 400.
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

class ProSiblingRoutesTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;
	private int $member_id;
	private int $outsider_id;
	private int $private_post_id;
	private int $public_post_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();

		$reactions = new \Jetonomy_Pro\Extensions\Reactions\Extension();
		$reactions->activate();
		$fields = new \Jetonomy_Pro\Extensions\Custom_Fields\Extension();
		$fields->activate();
		$pm = new \Jetonomy_Pro\Extensions\Private_Messaging\Extension();
		$pm->activate();
		$ai = new \Jetonomy_Pro\Extensions\Ai\Extension();
		$ai->activate();
		$ai->boot();
		foreach ( array(
			$reactions,
			$fields,
			$pm,
			new \Jetonomy_Pro\Extensions\Attachments\Rest(),
			new \Jetonomy_Pro\Extensions\Site_Announcements\Extension(),
			new \Jetonomy_Pro\Extensions\Email_Digest\Extension(),
			new \Jetonomy_Pro\Extensions\White_Label\Extension(),
			new \Jetonomy_Pro\Extensions\Advanced_Moderation\Extension(),
			new \Jetonomy_Pro\Extensions\Analytics\Extension(),
			new \Jetonomy_Pro\Extensions\Seo_Pro\Extension(),
		) as $ext ) {
			add_action( 'rest_api_init', array( $ext, 'register_routes' ) );
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cat     = Category::create( array( 'name' => 'Siblings', 'slug' => 'sib-' . uniqid() ) );
		$private = Space::create( array( 'title' => 'Private', 'slug' => 'sib-private-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		$public  = Space::create( array( 'title' => 'Public', 'slug' => 'sib-public-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		SpaceMember::add( $private, $this->member_id, 'member' );

		$this->private_post_id = Post::create( array( 'space_id' => $private, 'author_id' => $this->member_id, 'title' => 'Secret', 'slug' => 'sib-s-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
		$this->public_post_id  = Post::create( array( 'space_id' => $public, 'author_id' => $this->member_id, 'title' => 'Open', 'slug' => 'sib-o-' . uniqid(), 'content' => 'x', 'status' => 'publish' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function dispatch( string $method, string $route, array $params, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return $this->server->dispatch( $request );
	}

	private function assert_same_as_missing( \WP_REST_Response $hidden, \WP_REST_Response $missing ): void {
		$this->assertSame( 404, $hidden->get_status() );
		$this->assertNotSame( 'rest_no_route', $hidden->get_data()['code'] );
		$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'] );
		$this->assertSame( $missing->get_data()['message'], $hidden->get_data()['message'] );
	}

	private function silence( int $user_id ): void {
		$this->assertGreaterThan( 0, Restriction::ban( $user_id, 'silence', $this->admin_id ) );
	}

	// Reactions.

	public function test_silenced_member_cannot_react(): void {
		$this->silence( $this->outsider_id );
		$res = $this->dispatch( 'POST', "/jetonomy/v1/posts/{$this->public_post_id}/reactions", array( 'emoji' => 'thumbsup' ), $this->outsider_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $res->get_data()['code'] );
	}

	/** @covers-existing Reactions already answered an unreadable post like a missing one. */
	public function test_reaction_on_unreadable_post_reads_like_missing(): void {
		$this->assert_same_as_missing(
			$this->dispatch( 'POST', "/jetonomy/v1/posts/{$this->private_post_id}/reactions", array( 'emoji' => 'thumbsup' ), $this->outsider_id ),
			$this->dispatch( 'POST', '/jetonomy/v1/posts/999999/reactions', array( 'emoji' => 'thumbsup' ), $this->outsider_id )
		);
	}

	// Attachments.

	private function upload( int $user_id ): int {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png', 0 ) ?: 0;
	}

	public function test_attach_to_unreadable_post_reads_like_missing(): void {
		$aid = $this->upload( $this->outsider_id );
		wp_update_post( array( 'ID' => $aid, 'post_author' => $this->outsider_id ) );

		$this->assert_same_as_missing(
			$this->dispatch( 'POST', '/jetonomy-pro/v1/attachments', array( 'object_type' => 'post', 'object_id' => $this->private_post_id, 'attachment_id' => $aid ), $this->outsider_id ),
			$this->dispatch( 'POST', '/jetonomy-pro/v1/attachments', array( 'object_type' => 'post', 'object_id' => 999999, 'attachment_id' => $aid ), $this->outsider_id )
		);
	}

	public function test_silenced_member_cannot_attach(): void {
		$aid = $this->upload( $this->member_id );
		wp_update_post( array( 'ID' => $aid, 'post_author' => $this->member_id ) );
		$this->silence( $this->member_id );

		$res = $this->dispatch( 'POST', '/jetonomy-pro/v1/attachments', array( 'object_type' => 'post', 'object_id' => $this->public_post_id, 'attachment_id' => $aid ), $this->member_id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $res->get_data()['code'] );
	}

	public function test_detach_on_unreadable_post_reads_like_missing(): void {
		$aid  = $this->upload( $this->member_id );
		$link = \Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', $this->private_post_id, $aid, 0 );

		$this->assert_same_as_missing(
			$this->dispatch( 'DELETE', "/jetonomy-pro/v1/attachments/{$link}", array(), $this->outsider_id ),
			$this->dispatch( 'DELETE', '/jetonomy-pro/v1/attachments/999999', array(), $this->outsider_id )
		);
	}

	// Site announcements.

	private function manager(): int {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $uid )->add_cap( 'jetonomy_manage_spaces' );
		return $uid;
	}

	public function test_pin_of_unreadable_post_reads_like_missing(): void {
		$manager = $this->manager();
		$this->assert_same_as_missing(
			$this->dispatch( 'POST', "/jetonomy-pro/v1/site-announcements/{$this->private_post_id}", array(), $manager ),
			$this->dispatch( 'POST', '/jetonomy-pro/v1/site-announcements/999999', array(), $manager )
		);
	}

	public function test_silenced_manager_cannot_pin(): void {
		$manager = $this->manager();
		$this->silence( $manager );
		$res = $this->dispatch( 'POST', "/jetonomy-pro/v1/site-announcements/{$this->public_post_id}", array(), $manager );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_user_silenced', $res->get_data()['code'] );
	}

	// Enum arguments.

	/** @dataProvider bad_enum_requests */
	public function test_out_of_enum_value_is_rest_invalid_param( string $method, string $route, array $params ): void {
		$res = $this->dispatch( $method, $route, $params, $this->admin_id );
		$this->assertSame( 400, $res->get_status(), $route );
		$this->assertSame( 'rest_invalid_param', $res->get_data()['code'], $route );
	}

	public function bad_enum_requests(): array {
		return array(
			'fields context'   => array( 'GET', '/jetonomy/v1/fields', array( 'context' => 'bogus' ) ),
			'messages filter'  => array( 'GET', '/jetonomy/v1/conversations', array( 'filter' => 'bogus' ) ),
			'digest frequency' => array( 'PATCH', '/jetonomy/v1/users/me/digest-preferences', array( 'frequency' => 'hourly' ) ),
			'ai usage range'   => array( 'GET', '/jetonomy/v1/ai/usage', array( 'range' => 'forever' ) ),
		);
	}

	// Admin-only routes.

	/** @dataProvider admin_only_routes */
	public function test_member_gets_jetonomy_forbidden_on_admin_routes( string $route, array $params = array() ): void {
		$res = $this->dispatch( 'GET', $route, $params, $this->member_id );
		$this->assertSame( 403, $res->get_status(), $route );
		$this->assertSame( 'jetonomy_forbidden', $res->get_data()['code'], $route );
	}

	/** @dataProvider admin_only_routes */
	public function test_guest_on_admin_route_is_401( string $route, array $params = array() ): void {
		$this->assertSame( 401, $this->dispatch( 'GET', $route, $params, 0 )->get_status(), $route );
	}

	public function admin_only_routes(): array {
		return array(
			'white label settings' => array( '/jetonomy/v1/settings/white-label' ),
			'digest stats'         => array( '/jetonomy/v1/admin/digest/stats' ),
			'moderation rules'     => array( '/jetonomy/v1/moderation/rules' ),
			'moderation rule stats' => array( '/jetonomy/v1/moderation/rules/1/stats' ),
			'ai usage'             => array( '/jetonomy/v1/ai/usage' ),
			'ai usage summary'     => array( '/jetonomy/v1/ai/usage/summary' ),
			'announcement pins'    => array( '/jetonomy-pro/v1/site-announcements' ),
			'attachments batch'    => array( '/jetonomy-pro/v1/attachments/batch', array( 'object_type' => 'post', 'object_ids' => '1' ) ),
			'analytics overview'   => array( '/jetonomy/v1/analytics/overview' ),
			'analytics diff'       => array( '/jetonomy/v1/analytics/diff-report' ),
			'space seo'            => array( '/jetonomy/v1/spaces/1/seo' ),
		);
	}

	// AI usage range.

	/** @dataProvider bad_custom_ranges */
	public function test_unusable_custom_ai_usage_range_is_a_400( array $params ): void {
		$res = $this->dispatch( 'GET', '/jetonomy/v1/ai/usage', array_merge( array( 'range' => 'custom' ), $params ), $this->admin_id );
		$this->assertSame( 400, $res->get_status() );
		$this->assertStringContainsString( 'start and end date', wp_json_encode( $res->get_data() ) );
	}

	public function bad_custom_ranges(): array {
		return array(
			'no dates'         => array( array() ),
			'garbage'          => array( array( 'start' => 'whenever', 'end' => 'later' ) ),
			'end before start' => array( array( 'start' => '2026-05-10', 'end' => '2026-05-01' ) ),
		);
	}

	/** @covers-existing A valid custom AI range still answers. */
	public function test_valid_custom_ai_usage_range_is_accepted(): void {
		$res = $this->dispatch( 'GET', '/jetonomy/v1/ai/usage', array( 'range' => 'custom', 'start' => '2026-05-01', 'end' => '2026-05-10' ), $this->admin_id );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '2026-05-01', $res->get_data()['start'] );
	}
}
