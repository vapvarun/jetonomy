<?php
/**
 * Custom fields: a blank optional number field stays blank. (float) '' used to
 * store "0", and the topic then showed "0" as if the member had answered it.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;

class CustomFieldsBlankNumberTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $post_id;
	private string $slug;

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

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->slug = 'server-ram-' . uniqid();
		$req        = new WP_REST_Request( 'POST', '/jetonomy/v1/fields' );
		$req->set_body_params(
			array(
				'name'       => 'Server RAM in GB',
				'slug'       => $this->slug,
				'field_type' => 'number',
				'context'    => 'post',
			)
		);
		$this->server->dispatch( $req );

		$space         = (int) Space::create(
			array(
				'title'      => 'Self-Hosting Help',
				'slug'       => 'self-hosting-' . uniqid(),
				'visibility' => 'public',
			),
			$admin
		);
		$this->post_id = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $admin,
				'title'     => 'Which PHP version is safe for a fresh install?',
				'slug'      => 'php-version-' . uniqid(),
				'content'   => '<p>Planning a new server this quarter.</p>',
				'status'    => 'publish',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function save( string $value ): ?string {
		global $wpdb;
		$req = new WP_REST_Request( 'PATCH', "/jetonomy/v1/posts/{$this->post_id}/fields" );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( $this->slug => $value ) ) );
		$this->assertLessThan( 400, $this->server->dispatch( $req )->get_status() );
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT v.value FROM {$wpdb->prefix}jt_pro_field_values v JOIN {$wpdb->prefix}jt_pro_fields f ON f.id = v.field_id WHERE f.slug = %s AND v.object_type = 'post' AND v.object_id = %d",
				$this->slug,
				$this->post_id
			)
		);
	}

	public function test_blank_number_is_not_stored_as_zero(): void {
		$this->assertNotSame( '0', $this->save( '' ) );
	}

	public function test_real_numbers_still_save(): void {
		$this->assertSame( '16', $this->save( '16' ) );
		$this->assertSame( '0', $this->save( '0' ) );
	}
}
