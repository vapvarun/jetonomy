<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\Category;
use Jetonomy\Models\Tag;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

class SqlInjectionTest extends WP_UnitTestCase {

    private int $space_id;
    private int $admin_id;

    public function set_up(): void {
        parent::set_up();
        Schema::create_tables();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        $cat_id = Category::create(['name' => 'SQL Cat', 'slug' => 'sql-cat-' . uniqid()]);
        $this->space_id = Space::create([
            'title' => 'SQL Space', 'slug' => 'sql-space-' . uniqid(),
            'category_id' => $cat_id, 'visibility' => 'public',
        ]);
    }

    public function test_post_title_with_sql_injection(): void {
        $malicious = "Test'; DROP TABLE wp_jt_posts; --";
        $id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => $malicious, 'slug' => 'sql-test-' . uniqid(),
            'content' => '<p>safe</p>', 'content_plain' => 'safe',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $this->assertGreaterThan(0, $id);
        $post = Post::find($id);
        $this->assertNotNull($post);
        // Table must still exist — verify by querying it directly.
        // Note: SHOW TABLES does not list TEMPORARY tables used by WP test suite,
        // so we SELECT from the table instead. A successful query proves the table
        // was not dropped by the injection attempt.
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts");
        $this->assertNotNull($count, 'jt_posts table was dropped by SQL injection');
    }

    public function test_reply_content_with_sql_injection(): void {
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => 'Safe Post', 'slug' => 'safe-post-sql-' . uniqid(),
            'content' => '<p>safe</p>', 'content_plain' => 'safe',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $malicious = "Reply content'; UPDATE wp_jt_posts SET status='trash' WHERE 1=1; --";
        $reply_id = Reply::create([
            'post_id' => $post_id, 'author_id' => $this->admin_id,
            'content' => $malicious, 'content_plain' => $malicious, 'status' => 'publish',
        ]);
        $this->assertGreaterThan(0, $reply_id);
        // Original post must still be published
        $post = Post::find($post_id);
        $this->assertEquals('publish', $post->status);
    }

    public function test_search_query_with_sql_injection(): void {
        $request = new \WP_REST_Request('GET', '/jetonomy/v1/search');
        $request->set_query_params(['q' => "'; DROP TABLE wp_jt_posts; --", 'type' => 'all']);
        wp_set_current_user(0);
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [200, 400]);
        // Table must still exist — verify by querying it directly.
        // SHOW TABLES does not list TEMPORARY tables used by WP test suite.
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts");
        $this->assertNotNull($count, 'jt_posts table was dropped by SQL injection');
    }

    public function test_tag_name_with_sql_injection(): void {
        $malicious = "tag'; DELETE FROM wp_jt_tags; --";
        $id = Tag::find_or_create($malicious);
        $this->assertGreaterThan(0, $id);
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}jt_tags");
        $this->assertGreaterThan(0, $count, 'Tags table was emptied by injection');
    }

    public function test_user_profile_bio_with_sql_injection(): void {
        $profile = UserProfile::find_or_create($this->admin_id);
        $this->assertNotNull($profile);
        global $wpdb;
        $malicious = "Bio'; UPDATE wp_jt_user_profiles SET trust_level=5 WHERE 1=1; --";
        $wpdb->update($wpdb->prefix . 'jt_user_profiles', ['bio' => $malicious], ['user_id' => $this->admin_id]);
        // Trust level must NOT be 5 for all users
        $tl = (int) $wpdb->get_var("SELECT trust_level FROM {$wpdb->prefix}jt_user_profiles WHERE user_id = {$this->admin_id}");
        $this->assertLessThan(5, $tl);
    }
}
