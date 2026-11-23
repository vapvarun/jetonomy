<?php
/**
 * Space moderators may ban members of their own space, when the owner allows it.
 *
 * @package Jetonomy\Tests\Security
 */

namespace Jetonomy\Tests\Security;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Every refusal row of the 2.1.0 plan's threat table, plus the allowed path
 * and the space-admin lift that the route gate used to refuse.
 */
class ModeratorBanTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $space;
	private int $other_space;
	private int $owner;
	private int $space_admin;
	private int $mod;
	private int $peer_mod;
	private int $member;
	private int $outsider;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		Restriction::reset_memo();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->owner       = $this->user();
		$this->space_admin = $this->user();
		$this->mod         = $this->user();
		$this->peer_mod    = $this->user();
		$this->member      = $this->user();
		$this->outsider    = $this->user();
		$this->space       = (int) Space::create(
			array(
				'title'     => 'MB ' . uniqid(),
				'slug'      => 'mb-' . uniqid(),
				'author_id' => $this->owner,
			)
		);
		$this->other_space = (int) Space::create(
			array(
				'title' => 'MB2 ' . uniqid(),
				'slug'  => 'mb2-' . uniqid(),
			)
		);
		SpaceMember::add( $this->space, $this->owner, 'member' );
		SpaceMember::add( $this->space, $this->space_admin, 'admin' );
		SpaceMember::add( $this->space, $this->mod, 'moderator' );
		SpaceMember::add( $this->space, $this->peer_mod, 'moderator' );
		SpaceMember::add( $this->space, $this->member, 'member' );
		SpaceMember::add( $this->other_space, $this->outsider, 'member' );
		$this->toggle( true );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	private function user( string $role = 'subscriber' ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		UserProfile::find_or_create( $id );
		return $id;
	}

	private function toggle( bool $on ): void {
		update_option( 'jetonomy_settings', array( 'moderators_can_ban' => $on ) );
	}

	private function ban( int $as, int $target, string $type = 'space_ban', ?int $space = null ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/moderation/ban' );
		$request->set_body_params(
			array(
				'user_id'  => $target,
				'type'     => $type,
				'space_id' => $space ?? $this->space,
			)
		);
		$res = $this->server->dispatch( $request );
		Restriction::reset_memo();
		return $res;
	}

	private function lift( int $as, int $id ): \WP_REST_Response {
		wp_set_current_user( $as );
		$res = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/jetonomy/v1/moderation/ban/' . $id ) );
		Restriction::reset_memo();
		return $res;
	}

	private function code( \WP_REST_Response $res ): string {
		$data = $res->get_data();
		return (string) ( is_array( $data ) ? ( $data['code'] ?? '' ) : '' );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_toggle_off_keeps_moderators_out(): void {
		$this->toggle( false );
		$res = $this->ban( $this->mod, $this->member );
		$this->assertSame( 403, $res->get_status() );
		$this->assertFalse( Restriction::is_space_banned( $this->member, $this->space ) );
	}

	public function test_moderator_bans_a_member_of_their_space(): void {
		$res = $this->ban( $this->mod, $this->member );
		$this->assertSame( 201, $res->get_status() );
		$this->assertTrue( Restriction::is_space_banned( $this->member, $this->space ) );
		$row = Restriction::find( (int) $res->get_data()['restriction_id'] );
		$this->assertSame( $this->mod, (int) $row->issued_by );

		$again = $this->ban( $this->mod, $this->member );
		$this->assertSame( 409, $again->get_status(), 'The same ban twice is a conflict.' );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_moderator_cannot_ban_in_another_space(): void {
		$res = $this->ban( $this->mod, $this->outsider, 'space_ban', $this->other_space );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_moderator_cannot_ban_space_staff_or_the_owner(): void {
		foreach ( array( $this->space_admin, $this->peer_mod, $this->owner ) as $target ) {
			$res = $this->ban( $this->mod, $target );
			$this->assertSame( 403, $res->get_status() );
			$this->assertSame( 'jetonomy_cannot_ban_space_staff', $this->code( $res ) );
		}
	}

	public function test_moderator_cannot_ban_site_staff(): void {
		$site_admin = $this->user( 'administrator' );
		$site_mod   = $this->user();
		get_user_by( 'id', $site_mod )->add_cap( 'jetonomy_moderate' );
		SpaceMember::add( $this->space, $site_admin, 'member' );
		SpaceMember::add( $this->space, $site_mod, 'member' );
		$this->assertSame( 'jetonomy_cannot_ban_admin', $this->code( $this->ban( $this->mod, $site_admin ) ) );
		$this->assertSame( 'jetonomy_cannot_ban_moderator', $this->code( $this->ban( $this->mod, $site_mod ) ) );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_moderator_cannot_issue_site_wide_restrictions(): void {
		foreach ( array( 'global_ban', 'silence' ) as $type ) {
			$this->assertSame( 403, $this->ban( $this->mod, $this->member, $type )->get_status(), $type );
		}
		wp_set_current_user( $this->mod );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/moderation/ban' );
		$request->set_body_params(
			array(
				'type' => 'ip_ban',
				'ip'   => '203.0.113.20',
			)
		);
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
	}

	public function test_moderator_cannot_ban_someone_off_the_roster(): void {
		$res = $this->ban( $this->mod, $this->outsider );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'jetonomy_not_space_member', $this->code( $res ) );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_space_admin_still_bans_off_the_roster(): void {
		$this->assertSame( 201, $this->ban( $this->space_admin, $this->outsider )->get_status() );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_restricted_moderator_takes_no_action(): void {
		Restriction::ban( $this->mod, 'silence', 0 );
		Restriction::reset_memo();
		$this->assertSame( 403, $this->ban( $this->mod, $this->member )->get_status() );
	}

	public function test_moderator_cannot_ban_themselves(): void {
		$this->assertSame( 400, $this->ban( $this->mod, $this->mod )->get_status() );
	}

	public function test_moderator_cannot_flip_the_toggle(): void {
		$this->toggle( false );
		wp_set_current_user( $this->mod );
		$request = new WP_REST_Request( 'POST', '/jetonomy/v1/admin/settings' );
		$request->set_body_params( array( 'settings' => array( 'moderators_can_ban' => true ) ) );
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
		$this->assertFalse( \Jetonomy\Permissions\Permission_Engine::moderators_can_ban() );
	}

	public function test_only_an_administrator_changes_the_toggle_on_the_form(): void {
		$this->toggle( false );
		$delegate = $this->user();
		get_user_by( 'id', $delegate )->add_cap( 'jetonomy_manage_settings' );
		wp_set_current_user( $delegate );
		$clean = \Jetonomy\Settings::sanitize(
			array(
				'trust_thresholds'   => array(),
				'moderators_can_ban' => '1',
			)
		);
		$this->assertFalse( $clean['moderators_can_ban'] );
		wp_set_current_user( $this->user( 'administrator' ) );
		$clean = \Jetonomy\Settings::sanitize(
			array(
				'trust_thresholds'   => array(),
				'moderators_can_ban' => '1',
			)
		);
		$this->assertTrue( $clean['moderators_can_ban'] );
	}

	public function test_moderator_lifts_a_ban_in_their_space_only_while_allowed(): void {
		$id = Restriction::ban( $this->member, 'space_ban', $this->space_admin, $this->space );
		$this->toggle( false );
		$this->assertSame( 403, $this->lift( $this->mod, $id )->get_status() );
		$this->toggle( true );
		$this->assertSame( 200, $this->lift( $this->mod, $id )->get_status() );
		$this->assertFalse( Restriction::is_space_banned( $this->member, $this->space ) );
	}

	public function test_space_admin_without_site_caps_lifts_their_space_ban(): void {
		$this->toggle( false );
		$id = Restriction::ban( $this->member, 'space_ban', $this->space_admin, $this->space );
		$this->assertSame( 200, $this->lift( $this->space_admin, $id )->get_status() );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_space_admin_cannot_lift_a_site_wide_ban(): void {
		$id = Restriction::ban( $this->member, 'global_ban', 0 );
		$this->assertSame( 403, $this->lift( $this->space_admin, $id )->get_status() );
		$this->assertTrue( Restriction::is_banned( $this->member ) );
	}

	/** @covers-existing A refusal that held before 2.1.0 and must hold with the new space delegation. */
	public function test_moderator_cannot_lift_another_spaces_ban(): void {
		$id = Restriction::ban( $this->outsider, 'space_ban', 0, $this->other_space );
		$this->assertSame( 403, $this->lift( $this->mod, $id )->get_status() );
	}
}
