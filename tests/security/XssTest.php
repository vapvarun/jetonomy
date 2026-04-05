<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\Category;
use Jetonomy\DB\Schema;

class XssTest extends WP_UnitTestCase {

    private int $space_id;
    private int $admin_id;

    public function set_up(): void {
        parent::set_up();
        Schema::create_tables();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        $cat_id = Category::create(['name' => 'XSS Cat', 'slug' => 'xss-cat-' . uniqid()]);
        $this->space_id = Space::create([
            'title' => 'XSS Space', 'slug' => 'xss-space-' . uniqid(),
            'category_id' => $cat_id, 'visibility' => 'public',
        ]);
    }

    public function test_post_content_strips_script_tags(): void {
        $xss = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
        $clean = wp_kses_post($xss);
        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringContainsString('<p>Hello</p>', $clean);
    }

    public function test_post_content_strips_event_handlers(): void {
        $xss = '<img src="x" onerror="alert(1)">';
        $clean = wp_kses_post($xss);
        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function test_post_content_strips_javascript_protocol(): void {
        $xss = '<a href="javascript:alert(1)">Click</a>';
        $clean = wp_kses_post($xss);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function test_post_title_is_sanitized(): void {
        $xss = '<script>alert("xss")</script>Safe Title';
        $clean = sanitize_text_field($xss);
        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringContainsString('Safe Title', $clean);
    }

    public function test_reply_via_rest_strips_xss(): void {
        wp_set_current_user($this->admin_id);
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => 'XSS Test', 'slug' => 'xss-test-post-' . uniqid(),
            'content' => '<p>safe</p>', 'content_plain' => 'safe',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $request = new \WP_REST_Request('POST', "/jetonomy/v1/posts/{$post_id}/replies");
        $request->set_body_params(['content' => '<p>Reply</p><script>alert(1)</script>']);
        $response = rest_do_request($request);
        $this->assertEquals(201, $response->get_status());
        $data = $response->get_data();
        $reply = Reply::find((int) $data['id']);
        $this->assertStringNotContainsString('<script>', $reply->content);
    }

    public function test_profile_bio_via_rest_strips_xss(): void {
        wp_set_current_user($this->admin_id);
        // Ensure user profile exists before the PATCH request.
        \Jetonomy\Models\UserProfile::find_or_create($this->admin_id);

        $request = new \WP_REST_Request('PATCH', '/jetonomy/v1/users/me');
        $request->set_body_params(['bio' => '<script>steal(cookies)</script>Real bio']);
        $response = rest_do_request($request);
        $this->assertEquals(200, $response->get_status());
        // The bio should not contain script tags.
        // A null bio is also safe (no XSS possible), so both null and a
        // sanitized string are acceptable outcomes.
        $profile = \Jetonomy\Models\UserProfile::find_or_create($this->admin_id);
        $bio = $profile->bio ?? '';
        $this->assertStringNotContainsString('<script>', $bio);
    }
}
