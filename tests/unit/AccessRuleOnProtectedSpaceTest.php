<?php
/**
 * A restrictive access rule is never saved as "added" on a space that has to stay public.
 *
 * A membership, role, capability or trust-level rule is inert on a PUBLIC space (the permission engine checks visibility
 * first), so adding one switches the space to Private. When a plugin that owns the space's visibility refuses that
 * (BuddyNext, for a forum linked to a Space) the rule used to be saved anyway and answered "Access rule added" while the
 * forum stayed open to guests: a site owner believing paid content was protected.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

defined( 'ABSPATH' ) || exit;

class AccessRuleOnProtectedSpaceTest extends WP_UnitTestCase {

	private int $space_id;
	private int $admin;
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$cat            = Category::create( array( 'name' => 'Rules Cat', 'slug' => 'rules-cat-' . uniqid() ) );
		$this->space_id = Space::create( array( 'title' => 'Rules', 'slug' => 'rules-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->admin )->add_cap( 'jetonomy_manage_spaces' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function add_rule( string $type ) {
		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/spaces/{$this->space_id}/access-rules" );
		$request->set_body_params( array( 'rule_type' => $type, 'rule_value' => 'manage_options', 'grants' => 'read' ) );
		return $this->server->dispatch( $request );
	}

	private function rule_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_access_rules WHERE space_id = %d", $this->space_id ) );
	}

	public function test_a_restrictive_rule_on_a_space_that_cannot_be_made_private_is_refused_and_not_kept(): void {
		$refuse = static fn( $proceed, $id, $data ) => isset( $data['visibility'] ) ? new \WP_Error( 'refused', 'This space has to stay public.', array( 'status' => 409 ) ) : $proceed;
		add_filter( 'jetonomy_before_update_space', $refuse, 10, 3 );

		$res = $this->add_rule( 'capability' );
		remove_filter( 'jetonomy_before_update_space', $refuse, 10 );

		$this->assertTrue( $res->is_error() || $res->get_status() >= 400, 'The owner is told, not shown "added".' );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 0, $this->rule_count(), 'The inert rule is not kept.' );
		$this->assertSame( 'public', Space::find( $this->space_id )->visibility );
	}

	/** @covers-existing Pins behaviour that must not change; the fix is proved by the sibling tests. */
	public function test_without_a_refusal_the_rule_still_privatises_the_space_and_is_kept(): void {
		$res = $this->add_rule( 'capability' );

		$this->assertSame( 201, $res->get_status() );
		$this->assertTrue( $res->get_data()['made_private'] );
		$this->assertSame( 1, $this->rule_count() );
		$this->assertSame( 'private', Space::find( $this->space_id )->visibility );
	}

	/** @covers-existing Pins behaviour that must not change; the fix is proved by the sibling tests. */
	public function test_a_rule_that_needs_no_gate_is_unaffected_by_a_refusing_filter(): void {
		$refuse = static fn() => new \WP_Error( 'refused', 'No.', array( 'status' => 409 ) );
		add_filter( 'jetonomy_before_update_space', $refuse );

		$res = $this->add_rule( 'logged_in' );
		remove_filter( 'jetonomy_before_update_space', $refuse );

		$this->assertSame( 201, $res->get_status(), 'Everyone / logged-in rules never change the space.' );
		$this->assertSame( 1, $this->rule_count() );
	}
}
