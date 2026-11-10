<?php
/**
 * GET /webhooks returns one page with X-WP-Total, not every row.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;

class WebhooksListPagedTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'Jetonomy_Pro\Extensions\Webhooks\Extension' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		update_option( 'jetonomy_pro_extensions', array( 'webhooks' ) );
		update_option(
			'jetonomy_pro_license',
			array(
				'key'        => 'test-key',
				'status'     => 'valid',
				'expires'    => 'lifetime',
				'tier'       => 'lifetime',
				'item_name'  => 'Jetonomy Pro',
				'checked_at' => current_time( 'mysql', true ),
			)
		);
		$ext = new \Jetonomy_Pro\Extensions\Webhooks\Extension();
		$ext->activate();
		$ext->boot();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function test_list_is_paged(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_webhooks';
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		for ( $i = 1; $i <= 25; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'url'        => "https://hooks.example.test/{$i}",
					'events'     => '["post.created"]',
					'secret'     => 's',
					'is_active'  => 1,
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$req = new WP_REST_Request( 'GET', '/jetonomy/v1/webhooks' );
		$req->set_query_params( array( 'per_page' => 10, 'page' => 3 ) );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 5, $res->get_data() );
		$this->assertSame( '25', $res->get_headers()['X-WP-Total'] ?? null );
		$this->assertSame( '3', $res->get_headers()['X-WP-TotalPages'] ?? null );
	}
}
