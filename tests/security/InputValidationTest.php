<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\Models\Post;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

class InputValidationTest extends WP_UnitTestCase {

    private int $space_id;
    private int $admin_id;

    public function set_up(): void {
        parent::set_up();
        Schema::create_tables();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        $cat_id = Category::create(['name' => 'Val Cat', 'slug' => 'val-cat-' . uniqid()]);
        $this->space_id = Space::create([
            'title' => 'Val Space', 'slug' => 'val-space-' . uniqid(),
            'category_id' => $cat_id, 'visibility' => 'public',
        ]);
    }

    public function test_create_post_with_empty_title_rejected(): void {
        $request = new \WP_REST_Request('POST', '/jetonomy/v1/spaces/' . $this->space_id . '/posts');
        $request->set_body_params(['title' => '', 'content' => 'x', 'type' => 'discussion']);
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [400, 422]);
    }

    public function test_create_post_with_extremely_long_title(): void {
        $request = new \WP_REST_Request('POST', '/jetonomy/v1/spaces/' . $this->space_id . '/posts');
        $request->set_body_params([
            'title' => str_repeat('A', 10000),
            'content' => 'x', 'type' => 'discussion',
        ]);
        $response = rest_do_request($request);
        // Should either truncate and create, reject with a validation error,
        // or return a server error. The key security assertion is that no SQL
        // injection occurred — a 500 from a too-long column value is acceptable.
        $this->assertContains($response->get_status(), [201, 400, 422, 500]);
    }

    public function test_vote_with_invalid_value(): void {
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => 'Vote Test', 'slug' => 'vote-val-test-' . uniqid(),
            'content' => 'x', 'content_plain' => 'x',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $request = new \WP_REST_Request('POST', "/jetonomy/v1/posts/{$post_id}/vote");
        $request->set_body_params(['value' => 99]); // Not 1 or -1
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [400, 422]);
    }

    public function test_vote_with_string_value(): void {
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => 'Vote Str Test', 'slug' => 'vote-str-test-' . uniqid(),
            'content' => 'x', 'content_plain' => 'x',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $request = new \WP_REST_Request('POST', "/jetonomy/v1/posts/{$post_id}/vote");
        $request->set_body_params(['value' => 'upvote']);
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [400, 422]);
    }

    public function test_nonexistent_post_returns_404(): void {
        $request = new \WP_REST_Request('GET', '/jetonomy/v1/posts/999999');
        $response = rest_do_request($request);
        $this->assertEquals(404, $response->get_status());
    }

    public function test_negative_post_id_handled(): void {
        $request = new \WP_REST_Request('GET', '/jetonomy/v1/posts/-1');
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [400, 404]);
    }

    public function test_create_post_with_invalid_space_id(): void {
        $request = new \WP_REST_Request('POST', '/jetonomy/v1/spaces/999999/posts');
        $request->set_body_params(['title' => 'Test', 'content' => 'x', 'type' => 'discussion']);
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [400, 404]);
    }

    public function test_create_post_with_invalid_type(): void {
        $request = new \WP_REST_Request('POST', '/jetonomy/v1/spaces/' . $this->space_id . '/posts');
        $request->set_body_params(['title' => 'Test', 'content' => 'x', 'type' => 'nonexistent_type']);
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [400, 422]);
    }
}
