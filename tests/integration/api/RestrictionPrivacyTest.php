<?php
/**
 * A member's silence or ban is visible to staff and to the member, nobody else.
 *
 * GET /users/{id} and /users/by-login/{login} returned the active restriction
 * to every caller, so any visitor could read that a member was silenced or
 * banned from a space.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\API;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Restriction;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class RestrictionPrivacyTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $finn;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$this->finn = self::factory()->user->create( array( 'role' => 'subscriber', 'user_login' => 'finn_' . uniqid() ) );
		Restriction::ban( $this->finn, 'silence', self::factory()->user->create( array( 'role' => 'administrator' ) ), null, 'Repeated self-promotion.', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function restriction_seen_by( int $viewer ): array {
		wp_set_current_user( $viewer );
		\Jetonomy\Cache::flush();
		$login = get_userdata( $this->finn )->user_login;
		$seen  = array();
		foreach ( array( "/jetonomy/v1/users/{$this->finn}", "/jetonomy/v1/users/by-login/{$login}" ) as $route ) {
			$data   = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_data();
			$seen[] = $data['restriction'] ?? ( $data['data']['restriction'] ?? null );
		}
		return $seen;
	}

	public function test_guests_and_other_members_do_not_see_a_silence(): void {
		$this->assertSame( array( null, null ), $this->restriction_seen_by( 0 ), 'A guest must not learn a member is silenced' );
		$this->assertSame( array( null, null ), $this->restriction_seen_by( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ), 'Another member must not learn it either' );
	}

	/**
	 * @covers-existing Staff and the member themself still see it.
	 */
	public function test_the_member_and_staff_still_see_it(): void {
		$this->assertSame( array( 'silence', 'silence' ), $this->restriction_seen_by( $this->finn ) );
		$this->assertSame( array( 'silence', 'silence' ), $this->restriction_seen_by( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
	}
}
