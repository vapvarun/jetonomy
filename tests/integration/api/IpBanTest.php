<?php
namespace Jetonomy\Tests\Integration\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Restriction;

/**
 * IP bans issued and lifted through POST/GET/DELETE /moderation/ban.
 *
 * Before 2.0.2 the ip_ban restriction was enforced (Permission_Engine layer 0)
 * but only WP-CLI could create one, and it stored whatever --reason held as
 * the "IP", so `--reason=spam` banned nothing. The route now takes an `ip`
 * param, validated in Restriction::validate_ip_ban(), the one check every
 * entry point (wp-admin, REST, CLI) passes through.
 */
class IpBanTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin;
	private int $mod;
	private int $member;
	private string $remote_addr;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->remote_addr      = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->mod    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $this->mod )->add_cap( 'jetonomy_moderate' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server         = null;
		$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		parent::tear_down();
	}

	private function call( int $as, string $method, string $route, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $as );
		$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->server->dispatch( $req );
	}

	private function ip_ban( int $as, int $target, string $ip ): \WP_REST_Response {
		return $this->call(
			$as,
			'POST',
			'/moderation/ban',
			array(
				'user_id' => $target,
				'type'    => 'ip_ban',
				'ip'      => $ip,
			)
		);
	}

	public function test_an_administrator_bans_an_ip_lists_it_and_lifts_it(): void {
		$res = $this->ip_ban( $this->admin, $this->member, '203.0.113.7' );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( '203.0.113.7', $res->get_data()['ip'] );
		\Jetonomy\Cache::flush();
		$this->assertTrue( Restriction::is_ip_banned( '203.0.113.7' ) );

		// Listed with the other restrictions (default list), and by its own type filter.
		$id      = (int) $res->get_data()['restriction_id'];
		$default = array_map( static fn( $r ) => (int) $r->id, Restriction::list_active() );
		$this->assertContains( $id, $default, 'an IP ban shows on the Banned list so it can be lifted' );
		$listed = $this->call( $this->mod, 'GET', '/moderation/ban', array( 'type' => 'ip_ban' ) );
		$this->assertSame( 200, $listed->get_status() );

		// Lifting takes the authority issuing took: administrator only.
		$this->assertSame( 403, $this->call( $this->mod, 'DELETE', "/moderation/ban/{$id}" )->get_status() );
		$this->assertSame( 200, $this->call( $this->admin, 'DELETE', "/moderation/ban/{$id}" )->get_status() );
		\Jetonomy\Cache::flush();
		$this->assertFalse( Restriction::is_ip_banned( '203.0.113.7' ) );
	}

	public function test_ip_ban_is_validated_and_follows_the_ban_rules(): void {
		$this->assertContains( $this->ip_ban( $this->member, $this->member, '203.0.113.8' )->get_status(), array( 401, 403 ), 'a member cannot ban an IP' );
		$this->assertSame( 400, $this->ip_ban( $this->admin, $this->member, 'not-an-ip' )->get_status(), 'garbage is refused' );
		$this->assertSame( 400, $this->ip_ban( $this->admin, $this->member, '' )->get_status(), 'the ip param is required for ip_ban' );
		$this->assertSame( 400, $this->ip_ban( $this->admin, $this->member, '198.51.100.9' )->get_status(), 'an operator cannot ban the IP they are using' );
		foreach ( array( '::1', '127.0.0.2', '0.0.0.0' ) as $reserved ) {
			$res = $this->ip_ban( $this->admin, $this->member, $reserved );
			$this->assertSame( 'jetonomy_reserved_ip', $res->get_data()['code'] ?? '', "{$reserved} is loopback/reserved, never a visitor" );
		}
		// An IP ban blocks every account on the address, the owner's included,
		// and naming an ordinary member as the target hides whose address it is.
		$owner_lockout = $this->ip_ban( $this->mod, $this->member, '203.0.113.9' );
		$this->assertSame( 403, $owner_lockout->get_status(), 'a moderator (Editor) cannot ban an IP at all' );
		$this->assertSame( 'jetonomy_ip_ban_admin_only', $owner_lockout->get_data()['code'] ?? '' );

		// A public address: PHP 8.1 and 8.2 treat the 2001:db8::/32 documentation range as reserved.
		$this->assertSame( 201, $this->ip_ban( $this->admin, $this->member, '2a00:1450:4001:80b::200e' )->get_status(), 'IPv6 is accepted' );
		$this->assertSame( 409, $this->ip_ban( $this->admin, $this->member, '2a00:1450:4001:80b::200e' )->get_status(), 'an IP is banned once' );

		$this->assertSame( 0, Restriction::count_active( array( 'type' => 'ip_ban' ) ) - 1, 'only the one valid ban was written' );
	}

	public function test_model_refuses_an_ip_ban_whose_reason_is_not_an_ip(): void {
		// The CLI path: `wp jetonomy mod ban --type=ip_ban --reason=spam` used to "ban" the string "spam".
		$this->assertSame( 0, Restriction::ban( $this->member, 'ip_ban', $this->admin, null, 'spam' ) );
		$this->assertSame( 0, Restriction::count_active( array( 'type' => 'ip_ban' ) ) );
	}
}
