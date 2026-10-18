<?php
namespace Jetonomy\Tests\Security;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\UserProfile;
use Jetonomy\Permissions\Capabilities;

/**
 * Lifting a restriction takes the same authority as issuing it, on the
 * wp-admin AJAX screen and over REST alike. A silenced moderator could lift
 * their own silence from Jetonomy > Users, and any moderator could lift an
 * IP ban or an administrator's restriction on another moderator.
 */
class RestrictionLiftParityTest extends WP_Ajax_UnitTestCase {

	private const IP = '203.0.113.7';

	private WP_REST_Server $server;
	private int $admin;
	private int $mod;
	private int $peer_mod;
	private int $member;
	private string $remote_addr;

	public function set_up(): void {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Schema::create_tables();
		Restriction::reset_memo();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->remote_addr      = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$this->mod      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->peer_mod = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $this->mod )->add_cap( 'jetonomy_moderate' );
		get_user_by( 'id', $this->peer_mod )->add_cap( 'jetonomy_moderate' );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( $this->admin, $this->mod, $this->peer_mod, $this->member ) as $id ) {
			UserProfile::find_or_create( $id );
		}

		new \Jetonomy\Admin\Ajax\Users_Handler();
		new \Jetonomy\Admin\Ajax\Moderation_Handler();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server         = null;
		$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		parent::tear_down();
	}

	private function ajax( int $as, string $action, array $post ): array {
		wp_set_current_user( $as );
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( 'jetonomy_admin' ) ), $post );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json_* dies; the response is asserted.
		}
		$response             = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		Restriction::reset_memo();
		return is_array( $response ) ? $response : array();
	}

	private function rest_unban( int $as, int $id ): \WP_REST_Response {
		wp_set_current_user( $as );
		$res = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/jetonomy/v1/moderation/ban/' . $id ) );
		Restriction::reset_memo();
		return $res;
	}

	private function ip_ban(): int {
		return Restriction::ban( 0, 'ip_ban', $this->admin, null, self::IP );
	}

	public function test_ajax_refuses_a_silenced_moderator_lifting_their_own_silence(): void {
		$id = Restriction::ban( $this->mod, 'silence', $this->admin );

		$r = $this->ajax( $this->mod, 'jetonomy_unban_user', array( 'restriction_id' => (string) $id ) );

		$this->assertFalse( $r['success'] ?? true );
		$this->assertNotNull( Restriction::find( $id ), 'The silence must stay in place.' );
	}

	public function test_ajax_refuses_a_moderator_lifting_an_ip_ban(): void {
		$id = $this->ip_ban();

		$r = $this->ajax( $this->mod, 'jetonomy_unban_user', array( 'restriction_id' => (string) $id ) );

		$this->assertFalse( $r['success'] ?? true );
		$this->assertSame( 'Only a site administrator can lift an IP address ban.', $r['data'] ?? '' );
		$this->assertNotNull( Restriction::find( $id ) );
	}

	public function test_ajax_refuses_a_moderator_lifting_a_restriction_on_another_moderator(): void {
		$id = Restriction::ban( $this->peer_mod, 'silence', $this->admin );

		$r = $this->ajax( $this->mod, 'jetonomy_unban_user', array( 'restriction_id' => (string) $id ) );

		$this->assertFalse( $r['success'] ?? true );
		$this->assertSame( 'Only an administrator can lift a restriction on another moderator.', $r['data'] ?? '' );
		$this->assertNotNull( Restriction::find( $id ) );
	}

	public function test_ajax_refuses_a_silenced_moderator_issuing_a_ban(): void {
		Restriction::ban( $this->mod, 'silence', $this->admin );

		$r = $this->ajax(
			$this->mod,
			'jetonomy_ban_user',
			array(
				'user_id' => (string) $this->member,
				'type'    => 'silence',
			)
		);

		$this->assertFalse( $r['success'] ?? true );
		$this->assertFalse( Restriction::is_silenced( $this->member ), 'No restriction may be written.' );
	}

	public function test_rest_refuses_a_moderator_lifting_an_ip_ban(): void {
		$id = $this->ip_ban();

		$res = $this->rest_unban( $this->mod, $id );

		$this->assertSame( 403, $res->get_status() );
		$this->assertNotNull( Restriction::find( $id ) );
	}

	public function test_rest_refuses_a_moderator_lifting_a_restriction_on_another_moderator(): void {
		$id = Restriction::ban( $this->peer_mod, 'silence', $this->admin );

		$res = $this->rest_unban( $this->mod, $id );

		$this->assertSame( 403, $res->get_status() );
		$this->assertNotNull( Restriction::find( $id ) );
	}

	/**
	 * Positive cases: the rule refuses escalation only.
	 *
	 * @covers-existing
	 */
	public function test_moderator_and_admin_keep_their_lift_powers(): void {
		$member_silence = Restriction::ban( $this->member, 'silence', $this->mod );
		$r              = $this->ajax( $this->mod, 'jetonomy_unban_user', array( 'restriction_id' => (string) $member_silence ) );
		$this->assertTrue( $r['success'] ?? false, wp_json_encode( $r ) );
		$this->assertNull( Restriction::find( $member_silence ) );

		$member_ban = Restriction::ban( $this->member, 'global_ban', $this->mod );
		$this->assertSame( 200, $this->rest_unban( $this->mod, $member_ban )->get_status() );

		$ip = $this->ip_ban();
		$r  = $this->ajax( $this->admin, 'jetonomy_unban_user', array( 'restriction_id' => (string) $ip ) );
		$this->assertTrue( $r['success'] ?? false, wp_json_encode( $r ) );

		$ip = $this->ip_ban();
		$this->assertSame( 200, $this->rest_unban( $this->admin, $ip )->get_status() );

		$peer = Restriction::ban( $this->peer_mod, 'silence', $this->admin );
		$r    = $this->ajax( $this->admin, 'jetonomy_unban_user', array( 'restriction_id' => (string) $peer ) );
		$this->assertTrue( $r['success'] ?? false, wp_json_encode( $r ) );

		$peer = Restriction::ban( $this->peer_mod, 'silence', $this->admin );
		$this->assertSame( 200, $this->rest_unban( $this->admin, $peer )->get_status() );
	}

	/**
	 * The wp-admin Moderation and Content screens hand the acting moderator to
	 * Moderation_Service, whose refusal check already turns a silenced one away.
	 *
	 * @covers-existing
	 */
	public function test_wp_admin_trash_refuses_a_silenced_moderator(): void {
		$space_id = Space::create( array( 'title' => 'Lift Space', 'slug' => 'lift-space-' . uniqid(), 'visibility' => 'public' ) );
		$post_id  = Post::create( array( 'space_id' => $space_id, 'author_id' => $this->member, 'title' => 'Target', 'slug' => 'target-' . uniqid(), 'content' => '<p>x</p>' ) );
		Restriction::ban( $this->mod, 'silence', $this->admin );

		$r = $this->ajax(
			$this->mod,
			'jetonomy_trash_content',
			array(
				'object_type' => 'post',
				'object_id'   => (string) $post_id,
			)
		);

		$this->assertFalse( $r['success'] ?? true );
		$this->assertSame( 'publish', Post::find( $post_id )->status );
	}
}
