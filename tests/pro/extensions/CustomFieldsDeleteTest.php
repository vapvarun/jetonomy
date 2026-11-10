<?php
/**
 * Custom fields: DELETE /fields/{id} deactivates by default and, with
 * force=true, deletes the field and every stored value for good.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;

class CustomFieldsDeleteTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		$fields = new \Jetonomy_Pro\Extensions\Custom_Fields\Extension();
		$fields->activate();
		$fields->boot();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function field_with_value(): int {
		global $wpdb;
		$req = new WP_REST_Request( 'POST', '/jetonomy/v1/fields' );
		$req->set_body_params(
			array(
				'name'       => 'Delete me',
				'slug'       => 'delete-me-' . uniqid(),
				'field_type' => 'text',
				'context'    => 'profile',
			)
		);
		$id = (int) $this->server->dispatch( $req )->get_data()['data']['id'];
		$wpdb->insert(
			$wpdb->prefix . 'jt_pro_field_values',
			array(
				'field_id'    => $id,
				'object_type' => 'user',
				'object_id'   => $this->admin_id,
				'value'       => 'x',
			)
		);
		return $id;
	}

	private function delete( int $id, ?bool $force ): \WP_REST_Response {
		$req = new WP_REST_Request( 'DELETE', "/jetonomy/v1/fields/{$id}" );
		if ( null !== $force ) {
			$req->set_param( 'force', $force );
		}
		return $this->server->dispatch( $req );
	}

	public function test_delete_without_force_only_deactivates(): void {
		global $wpdb;
		$id = $this->field_with_value();

		$this->assertSame( 200, $this->delete( $id, null )->get_status() );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$wpdb->prefix}jt_pro_fields WHERE id = %d", $id ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_field_values WHERE field_id = %d", $id ) ) );
	}

	public function test_force_delete_removes_field_and_values(): void {
		global $wpdb;
		$id = $this->field_with_value();

		$res = $this->delete( $id, true );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['deleted'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_pro_fields WHERE id = %d", $id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_field_values WHERE field_id = %d", $id ) ) );
		$this->assertSame( 404, $this->delete( $id, true )->get_status() );
	}

	public function test_member_cannot_delete(): void {
		$id = $this->field_with_value();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertContains( $this->delete( $id, true )->get_status(), array( 401, 403 ) );
	}
}
