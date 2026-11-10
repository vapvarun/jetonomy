<?php
/**
 * QA 10430815956: SEO Pro saved "not a url" as http://not%20a%20url and the
 * space page printed it as its canonical, which tells search engines to drop
 * the page. A bad address is refused, and one saved before is never printed.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy_Pro\Extensions\Seo_Pro\Extension as Seo_Pro;

class SeoProAddressTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Seo_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		$cat         = Category::create( array( 'name' => 'SEO', 'slug' => 'seo-' . uniqid() ) );
		$this->space = Space::create( array( 'title' => 'SEO', 'slug' => 'seo-' . uniqid(), 'category_id' => $cat ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- fresh server per test.
		$seo            = new Seo_Pro();
		add_action( 'rest_api_init', array( $seo, 'register_routes' ) );
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function patch( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', "/jetonomy/v1/spaces/{$this->space}/seo" );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	}

	public function test_rest_refuses_what_is_not_an_address(): void {
		$this->assertSame( 400, $this->patch( array( 'canonical_base' => 'not a url' ) )->get_status() );
		$this->assertSame( 400, $this->patch( array( 'og_image' => '/relative/image.png' ) )->get_status(), 'A canonical or share image must be absolute.' );
		$ok = $this->patch( array( 'canonical_base' => 'https://example.com/forum' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'https://example.com/forum', $ok->get_data()['data']['canonical_base'] );
	}

	public function test_an_address_saved_before_the_check_is_never_printed(): void {
		global $wpdb;
		$wpdb->update( \Jetonomy\table( 'spaces' ), array( 'settings' => wp_json_encode( array( 'seo' => array( 'canonical_base' => 'http://not%20a%20url' ) ) ) ), array( 'id' => $this->space ) );
		\Jetonomy\Cache::flush();

		$request = new WP_REST_Request( 'GET', "/jetonomy/v1/spaces/{$this->space}/seo" );
		$this->assertSame( '', rest_get_server()->dispatch( $request )->get_data()['data']['canonical_base'] );
	}
}
