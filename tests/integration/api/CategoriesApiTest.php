<?php
namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;
use Jetonomy\API\Categories_Controller;

class CategoriesApiTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	private string $namespace = 'jetonomy/v1';

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		// Bootstrap REST server.
		global $wp_rest_server;
		$this->server    = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Register routes if the controller hasn't been registered by the plugin bootstrap.
		$controller = new Categories_Controller();
		$controller->register_routes();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function do_request( string $method, string $route, array $params = [], ?int $user_id = null ): \WP_REST_Response|\WP_Error {
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		} else {
			wp_set_current_user( 0 );
		}

		$request = new WP_REST_Request( $method, '/' . $this->namespace . $route );
		if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
			$request->set_body_params( $params );
		} else {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		return $this->server->dispatch( $request );
	}

	public function test_get_categories_returns_200(): void {
		$response = $this->do_request( 'GET', '/categories' );
		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_get_categories_returns_array(): void {
		Category::create( [ 'name' => 'API Cat 1', 'slug' => 'api-cat-1-' . uniqid() ] );
		Category::create( [ 'name' => 'API Cat 2', 'slug' => 'api-cat-2-' . uniqid() ] );

		$response = $this->do_request( 'GET', '/categories' );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
	}

	public function test_get_categories_returns_only_top_level(): void {
		$parent_id = Category::create( [ 'name' => 'Parent', 'slug' => 'parent-api-' . uniqid() ] );
		Category::create( [ 'name' => 'Child', 'slug' => 'child-api-' . uniqid(), 'parent_id' => $parent_id ] );

		$response = $this->do_request( 'GET', '/categories' );
		$data     = $response->get_data();

		// paginated_response() wraps items in { data: [...], meta: {...} }.
		$items = $data['data'] ?? $data;
		$names = array_column( $items, 'name' );
		$this->assertContains( 'Parent', $names );
		$this->assertNotContains( 'Child', $names );
	}

	public function test_post_categories_requires_auth(): void {
		// Unauthenticated request should be denied. REST_Auth::auth_mutation
		// returns 401 rest_not_logged_in for anonymous callers (correct HTTP
		// semantics); the legacy pre-migration callback returned 403.
		$response = $this->do_request( 'POST', '/categories', [ 'name' => 'Unauth Cat' ] );
		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_post_categories_creates_category_for_admin(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$admin    = get_user_by( 'id', $admin_id );
		$admin->add_cap( 'jetonomy_manage_categories' );

		$response = $this->do_request( 'POST', '/categories', [
			'name'       => 'New Category',
			'visibility' => 'public',
		], $admin_id );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'New Category', $data['name'] );
		$this->assertNotEmpty( $data['slug'] );
		$this->assertGreaterThan( 0, $data['id'] );
	}

	public function test_post_categories_auto_generates_slug(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$admin    = get_user_by( 'id', $admin_id );
		$admin->add_cap( 'jetonomy_manage_categories' );

		$response = $this->do_request( 'POST', '/categories', [
			'name' => 'Auto Slug Test',
		], $admin_id );

		$data = $response->get_data();
		$this->assertEquals( 'auto-slug-test', $data['slug'] );
	}

	public function test_get_single_category_returns_200(): void {
		$id = Category::create( [ 'name' => 'Single', 'slug' => 'single-api-' . uniqid() ] );

		$response = $this->do_request( 'GET', "/categories/{$id}" );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'Single', $data['name'] );
	}

	public function test_get_single_category_returns_404_for_missing(): void {
		$response = $this->do_request( 'GET', '/categories/999999' );
		$this->assertEquals( 404, $response->get_status() );
	}

	public function test_get_single_category_includes_spaces(): void {
		$cat_id = Category::create( [ 'name' => 'With Spaces', 'slug' => 'with-spaces-api-' . uniqid() ] );
		Space::create( [
			'title'       => 'Space One',
			'slug'        => 'space-one-api-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		] );

		$response = $this->do_request( 'GET', "/categories/{$cat_id}" );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'spaces', $data );
		$this->assertNotEmpty( $data['spaces'] );
	}

	public function test_post_categories_requires_name(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$admin    = get_user_by( 'id', $admin_id );
		$admin->add_cap( 'jetonomy_manage_categories' );

		$response = $this->do_request( 'POST', '/categories', [], $admin_id );
		// Should return 400 (missing required field) or 422 validation error.
		$this->assertContains( $response->get_status(), [ 400, 422, 500 ] );
	}

	public function test_post_categories_persists_to_database(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$admin    = get_user_by( 'id', $admin_id );
		$admin->add_cap( 'jetonomy_manage_categories' );

		$this->do_request( 'POST', '/categories', [
			'name'       => 'Persisted Cat',
			'visibility' => 'public',
		], $admin_id );

		$cat = Category::find_by_slug( 'persisted-cat' );
		$this->assertNotNull( $cat );
		$this->assertEquals( 'Persisted Cat', $cat->name );
	}
}
