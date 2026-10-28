<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * A rule's roster role follows its grant for every writer, and the grant can
 * be changed in place (REST PATCH and the wp-admin select share update_grants()).
 *
 * Until 2.0.7 a rule created over REST without space_role was stored as Viewer
 * even when it granted Participate.
 */
class AccessRuleGrantsEditTest extends WP_UnitTestCase {

	private int $space_id;
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat            = Category::create( array( 'name' => 'Grants Cat', 'slug' => 'grants-cat-' . uniqid() ) );
		$this->space_id = Space::create( array( 'title' => 'Grants', 'slug' => 'grants-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function rest( string $method, string $route, array $params, int $user_id ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		$request->set_body_params( $params );
		return $this->server->dispatch( $request );
	}

	private function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $id )->add_cap( 'jetonomy_manage_spaces' );
		return $id;
	}

	public function test_rest_create_without_role_records_the_role_the_grant_implies(): void {
		$res = $this->rest( 'POST', "/spaces/{$this->space_id}/access-rules", array( 'rule_type' => 'logged_in', 'grants' => 'participate' ), $this->admin() );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'member', AccessRule::find( (int) $res->get_data()['data']->id )->space_role );
	}

	public function test_a_requested_role_never_outranks_the_grant(): void {
		$id = AccessRule::create( array( 'space_id' => $this->space_id, 'rule_type' => 'logged_in', 'grants' => 'read', 'space_role' => 'admin' ) );
		$this->assertSame( 'viewer', AccessRule::find( $id )->space_role );
	}

	public function test_patch_changes_grant_and_role_together(): void {
		// A role rule: the only kind besides capability where Full is kept (2.0.7).
		$id  = AccessRule::create( array( 'space_id' => $this->space_id, 'rule_type' => 'role', 'rule_value' => 'editor', 'grants' => 'read' ) );
		$res = $this->rest( 'PATCH', "/access-rules/{$id}", array( 'grants' => 'full' ), $this->admin() );
		$this->assertSame( 200, $res->get_status() );
		$rule = AccessRule::find( $id );
		$this->assertSame( 'full', $rule->grants );
		$this->assertSame( 'member', $rule->space_role );
	}

	public function test_patch_is_refused_to_a_subscriber_and_rejects_bad_input(): void {
		$id  = AccessRule::create( array( 'space_id' => $this->space_id, 'rule_type' => 'logged_in', 'grants' => 'read' ) );
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame( 403, $this->rest( 'PATCH', "/access-rules/{$id}", array( 'grants' => 'full' ), $sub )->get_status() );
		$this->assertSame( 'read', AccessRule::find( $id )->grants );

		$admin = $this->admin();
		$this->assertSame( 400, $this->rest( 'PATCH', "/access-rules/{$id}", array( 'grants' => 'moderate' ), $admin )->get_status() );
		$this->assertSame( 404, $this->rest( 'PATCH', '/access-rules/999999', array( 'grants' => 'read' ), $admin )->get_status() );
	}

	public function test_migration_corrects_rules_saved_with_the_old_default(): void {
		global $wpdb;
		$id = AccessRule::create( array( 'space_id' => $this->space_id, 'rule_type' => 'logged_in', 'grants' => 'participate' ) );
		$wpdb->update( $wpdb->prefix . 'jt_access_rules', array( 'space_role' => 'viewer' ), array( 'id' => $id ) );

		require_once JETONOMY_DIR . 'includes/db/migrations/class-migration_2_0_7.php';
		( new \Jetonomy\DB\Migrations\Migration_2_0_7() )->up();

		$this->assertSame( 'member', AccessRule::find( $id )->space_role );
	}
}
