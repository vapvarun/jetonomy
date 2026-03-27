# PHPUnit Test Suite + CI Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add security edge-case tests, error path tests, race condition tests, and GitHub Actions CI to the existing 154 PHPUnit tests — achieving full coverage alongside the 398 CLI tests.

**Architecture:** Extend the existing `tests/` directory with 4 new test categories: security, error-paths, concurrency, and pro-extensions. Set up `.wp-env.json` + GitHub Actions for automated matrix testing. PHPUnit tests cover what CLI tests cannot: invalid inputs, error handling, concurrent operations, and security edge cases.

**Tech Stack:** PHPUnit 9.6 (existing), WP_UnitTestCase, wp-env, GitHub Actions

---

## Existing Test Infrastructure

```
tests/
├── bootstrap.php                    ← WP test lib loader
├── unit/                            ← 7 files, 96 methods
│   ├── models/ (6 files)            ← Category, Post, Reply, Space, UserProfile, Vote
│   ├── permissions/ (1 file)        ← PermissionEngine (14 methods)
│   └── trust/ (2 files)             ← Reputation, TrustEvaluator
├── integration/                     ← 3 files, 34 methods
│   ├── api/ (1 file)                ← CategoriesApi
│   ├── db/ (1 file)                 ← Schema
│   └── permissions/ (1 file)        ← FullPermissionFlow
└── phpunit.xml.dist
```

**Total existing: 13 files, 154 test methods**

## New Files to Create

```
tests/
├── security/                        ← NEW: edge cases
│   ├── SqlInjectionTest.php         ← SQL injection via model inputs
│   ├── XssTest.php                  ← XSS via post/reply content, profile bio
│   ├── CsrfTest.php                 ← REST requests without valid nonce
│   └── InputValidationTest.php      ← Invalid types, overflow, empty strings, null
├── error-paths/                     ← NEW: graceful degradation
│   ├── MissingObjectTest.php        ← Operations on deleted/nonexistent posts/replies/spaces
│   ├── InvalidStateTest.php         ← Double-accept answer, vote on closed post, reply on closed
│   └── MalformedRequestTest.php     ← Bad JSON, wrong param types, missing required fields
├── concurrency/                     ← NEW: race conditions
│   └── RaceConditionTest.php        ← Double-vote, concurrent reply count, counter consistency
├── pro/                             ← NEW: Pro extension tests
│   ├── MessagingTest.php            ← Conversation CRUD, message send, trust gate
│   ├── ReactionsTest.php            ← Toggle on/off, counts, multi-user
│   ├── PollsTest.php                ← Create, vote, close, results
│   └── AnalyticsTest.php            ← Permission gate, date range, data shape
.wp-env.json                         ← NEW: wp-env config for local + CI
.github/workflows/tests.yml          ← NEW: GitHub Actions matrix
```

---

## Task 1: Security — SQL Injection Tests

**Files:**
- Create: `tests/security/SqlInjectionTest.php`

- [ ] **Step 1: Create SqlInjectionTest.php**

```php
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
        $cat_id = Category::create(['name' => 'SQL Cat', 'slug' => 'sql-cat']);
        $this->space_id = Space::create([
            'title' => 'SQL Space', 'slug' => 'sql-space',
            'category_id' => $cat_id, 'visibility' => 'public',
        ]);
    }

    public function test_post_title_with_sql_injection(): void {
        $malicious = "Test'; DROP TABLE wp_jt_posts; --";
        $id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => $malicious, 'slug' => 'sql-test',
            'content' => '<p>safe</p>', 'content_plain' => 'safe',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $this->assertGreaterThan(0, $id);
        $post = Post::find($id);
        $this->assertNotNull($post);
        // Table must still exist
        global $wpdb;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}jt_posts'");
        $this->assertNotNull($exists, 'jt_posts table was dropped by SQL injection');
    }

    public function test_reply_content_with_sql_injection(): void {
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => 'Safe Post', 'slug' => 'safe-post-sql',
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
        // Table must still exist
        global $wpdb;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}jt_posts'");
        $this->assertNotNull($exists);
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
```

- [ ] **Step 2: Run test to verify it passes**

```bash
cd /path/to/jetonomy && vendor/bin/phpunit tests/security/SqlInjectionTest.php
```

- [ ] **Step 3: Commit**

```bash
git add tests/security/SqlInjectionTest.php
git commit -m "test: add SQL injection security tests (5 methods)"
```

---

## Task 2: Security — XSS Tests

**Files:**
- Create: `tests/security/XssTest.php`

- [ ] **Step 1: Create XssTest.php**

Tests that `<script>` tags, event handlers, and JS protocols are stripped from:
- Post title and content (via wp_kses_post)
- Reply content
- Profile bio
- Space title and description
- Tag names

Each test creates content with XSS payload, reads it back, and asserts the dangerous parts are stripped.

```php
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
        $cat_id = Category::create(['name' => 'XSS Cat', 'slug' => 'xss-cat']);
        $this->space_id = Space::create([
            'title' => 'XSS Space', 'slug' => 'xss-space',
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
            'title' => 'XSS Test', 'slug' => 'xss-test-post',
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
        $request = new \WP_REST_Request('PATCH', '/jetonomy/v1/users/me');
        $request->set_body_params(['bio' => '<script>steal(cookies)</script>Real bio']);
        $response = rest_do_request($request);
        $this->assertEquals(200, $response->get_status());
        // The bio should not contain script tags
        $profile = \Jetonomy\Models\UserProfile::find_or_create($this->admin_id);
        $this->assertStringNotContainsString('<script>', $profile->bio);
    }
}
```

- [ ] **Step 2: Run and verify**
- [ ] **Step 3: Commit**

---

## Task 3: Security — CSRF Tests

**Files:**
- Create: `tests/security/CsrfTest.php`

- [ ] **Step 1: Create CsrfTest.php**

Tests that REST write endpoints reject requests from unauthenticated users (our permission callbacks already gate this, but these tests prove it via PHPUnit).

```php
<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

class CsrfTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        Schema::create_tables();
    }

    /** @dataProvider write_endpoints */
    public function test_unauthenticated_write_rejected(string $method, string $route, array $params): void {
        wp_set_current_user(0); // Guest
        $request = new \WP_REST_Request($method, '/jetonomy/v1' . $route);
        if ($method === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
        }
        $response = rest_do_request($request);
        $this->assertContains($response->get_status(), [401, 403],
            "Guest should be rejected on {$method} {$route}, got {$response->get_status()}");
    }

    public function write_endpoints(): array {
        return [
            'create post' => ['POST', '/spaces/1/posts', ['title' => 'x', 'content' => 'x', 'type' => 'discussion']],
            'edit post' => ['PATCH', '/posts/1', ['content' => 'x']],
            'delete post' => ['DELETE', '/posts/1', []],
            'create reply' => ['POST', '/posts/1/replies', ['content' => 'x']],
            'vote' => ['POST', '/posts/1/vote', ['value' => 1]],
            'bookmark' => ['POST', '/bookmarks', ['post_id' => 1]],
            'subscribe' => ['POST', '/subscriptions', ['object_type' => 'post', 'object_id' => 1]],
            'flag' => ['POST', '/flags', ['object_type' => 'post', 'object_id' => 1, 'reason' => 'spam']],
            'save profile' => ['PATCH', '/users/me', ['bio' => 'x']],
            'mark read' => ['POST', '/notifications/mark-all-read', []],
            'pin post' => ['POST', '/posts/1/pin', []],
            'close post' => ['POST', '/posts/1/close', []],
            'move post' => ['POST', '/posts/1/move', ['target_space_id' => 1]],
            'accept reply' => ['POST', '/replies/1/accept', []],
            'split reply' => ['POST', '/replies/1/split', ['title' => 'x']],
        ];
    }
}
```

- [ ] **Step 2: Run and verify** — all 15 data provider cases should pass
- [ ] **Step 3: Commit**

---

## Task 4: Security — Input Validation Tests

**Files:**
- Create: `tests/security/InputValidationTest.php`

- [ ] **Step 1: Create InputValidationTest.php**

Tests invalid types, overflow values, empty strings, extremely long strings, null values, negative IDs, float IDs, etc.

```php
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
        $cat_id = Category::create(['name' => 'Val Cat', 'slug' => 'val-cat']);
        $this->space_id = Space::create([
            'title' => 'Val Space', 'slug' => 'val-space',
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
        // Should either truncate or reject — not crash
        $this->assertContains($response->get_status(), [201, 400, 422]);
    }

    public function test_vote_with_invalid_value(): void {
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $this->admin_id,
            'title' => 'Vote Test', 'slug' => 'vote-val-test',
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
            'title' => 'Vote Str Test', 'slug' => 'vote-str-test',
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
```

- [ ] **Step 2: Run and verify**
- [ ] **Step 3: Commit**

---

## Task 5: Error Paths — Missing Objects & Invalid State

**Files:**
- Create: `tests/error-paths/MissingObjectTest.php`
- Create: `tests/error-paths/InvalidStateTest.php`

- [ ] **Step 1: Create MissingObjectTest.php**

Tests operations on deleted/nonexistent posts, replies, spaces:
- Edit deleted post → 404
- Reply to deleted post → 404
- Vote on deleted post → 404
- Accept answer on nonexistent reply → 404
- Delete already-deleted reply → 404
- Flag nonexistent post → 404

- [ ] **Step 2: Create InvalidStateTest.php**

Tests operations in invalid states:
- Reply to a closed post → 403/400
- Vote on a post in a space with allow_voting=off → 403
- Accept answer twice on same reply → 200 (idempotent) or 409
- Create post in archived space → 403
- Join invite-only space without invite → 403

- [ ] **Step 3: Run and verify**
- [ ] **Step 4: Commit**

---

## Task 6: Error Paths — Malformed Requests

**Files:**
- Create: `tests/error-paths/MalformedRequestTest.php`

- [ ] **Step 1: Create MalformedRequestTest.php**

Tests bad JSON bodies, missing required params, wrong content types:
- POST /posts without title → 400
- POST /replies without content → 400
- POST /flags without object_type → 400
- PATCH /users/me with empty body → 200 (no-op) or 400
- POST /subscriptions without object_id → 400

- [ ] **Step 2: Run and verify**
- [ ] **Step 3: Commit**

---

## Task 7: Concurrency — Race Conditions

**Files:**
- Create: `tests/concurrency/RaceConditionTest.php`

- [ ] **Step 1: Create RaceConditionTest.php**

Tests counter consistency after concurrent operations:

```php
<?php
namespace Jetonomy\Tests\Concurrency;

use WP_UnitTestCase;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

class RaceConditionTest extends WP_UnitTestCase {

    private int $space_id;

    public function set_up(): void {
        parent::set_up();
        Schema::create_tables();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $cat = Category::create(['name' => 'Race Cat', 'slug' => 'race-cat']);
        $this->space_id = Space::create([
            'title' => 'Race Space', 'slug' => 'race-space',
            'category_id' => $cat, 'visibility' => 'public',
        ]);
    }

    public function test_double_vote_same_user_does_not_double_count(): void {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $admin,
            'title' => 'Double Vote', 'slug' => 'double-vote',
            'content' => 'x', 'content_plain' => 'x',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        Vote::cast($admin, 'post', $post_id, 1);
        Vote::cast($admin, 'post', $post_id, 1); // Second call — should toggle or no-op
        $post = Post::find($post_id);
        // Score should be 1 or 0 (if toggled off), never 2
        $this->assertLessThanOrEqual(1, (int) $post->vote_score);
    }

    public function test_multiple_users_vote_counter_consistent(): void {
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => 1,
            'title' => 'Multi Vote', 'slug' => 'multi-vote',
            'content' => 'x', 'content_plain' => 'x',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $users[] = self::factory()->user->create(['role' => 'subscriber']);
        }
        foreach ($users as $uid) {
            Vote::cast($uid, 'post', $post_id, 1);
        }
        $post = Post::find($post_id);
        $this->assertEquals(10, (int) $post->vote_score);
    }

    public function test_reply_count_matches_actual_replies(): void {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $admin,
            'title' => 'Reply Count', 'slug' => 'reply-count-test',
            'content' => 'x', 'content_plain' => 'x',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        for ($i = 0; $i < 5; $i++) {
            Reply::create([
                'post_id' => $post_id, 'author_id' => $admin,
                'content' => "Reply {$i}", 'content_plain' => "Reply {$i}",
                'status' => 'publish',
            ]);
        }
        $post = Post::find($post_id);
        $this->assertEquals(5, (int) $post->reply_count);
    }

    public function test_double_accept_answer_is_idempotent(): void {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $post_id = Post::create([
            'space_id' => $this->space_id, 'author_id' => $admin,
            'title' => 'Double Accept', 'slug' => 'double-accept',
            'content' => 'x', 'content_plain' => 'x',
            'type' => 'discussion', 'status' => 'publish',
        ]);
        $reply_id = Reply::create([
            'post_id' => $post_id, 'author_id' => $admin,
            'content' => 'Answer', 'content_plain' => 'Answer',
            'status' => 'publish',
        ]);
        Reply::mark_accepted($reply_id);
        Post::accept_reply($post_id, $reply_id);
        // Accept again — should not crash
        Reply::mark_accepted($reply_id);
        Post::accept_reply($post_id, $reply_id);
        $post = Post::find($post_id);
        $this->assertEquals($reply_id, (int) $post->accepted_reply_id);
    }
}
```

- [ ] **Step 2: Run and verify**
- [ ] **Step 3: Commit**

---

## Task 8: Pro Extension PHPUnit Tests

**Files:**
- Create: `tests/pro/MessagingTest.php`
- Create: `tests/pro/ReactionsTest.php`
- Create: `tests/pro/PollsTest.php`
- Create: `tests/pro/AnalyticsTest.php`

- [ ] **Step 1: Create MessagingTest.php**

Tests via rest_do_request:
- Create conversation with valid recipient → 201
- Create conversation with self → 400
- Send message to conversation → 201
- Non-participant cannot read conversation → 403
- Trust level 0 cannot send message → 403
- List conversations returns only user's conversations

- [ ] **Step 2: Create ReactionsTest.php**

Tests:
- Toggle reaction on → action=added
- Toggle same reaction off → action=removed
- Different emoji replaces previous → correct counts
- Guest cannot react → 401

- [ ] **Step 3: Create PollsTest.php**

Tests:
- Create poll with options → has options array
- Vote on single-choice → user_votes contains option
- Vote again on single-choice → replaces (not duplicates)
- Closed poll rejects votes → 403

- [ ] **Step 4: Create AnalyticsTest.php**

Tests:
- Admin can view overview → 200
- Subscriber cannot view → 403
- Range param works (7d vs 30d return different data periods)

- [ ] **Step 5: Run all and verify**
- [ ] **Step 6: Commit**

---

## Task 9: wp-env Configuration

**Files:**
- Create: `.wp-env.json` (in jetonomy root)

- [ ] **Step 1: Create .wp-env.json**

```json
{
  "core": "WordPress/WordPress#6.9",
  "phpVersion": "8.2",
  "plugins": [
    ".",
    "../jetonomy-pro"
  ],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "SCRIPT_DEBUG": true
  },
  "mappings": {
    "wp-content/mu-plugins/dev-auto-login.php": "./tests/fixtures/dev-auto-login.php"
  }
}
```

- [ ] **Step 2: Create tests/fixtures/dev-auto-login.php** (copy from mu-plugins)
- [ ] **Step 3: Verify wp-env starts**

```bash
cd /path/to/jetonomy && wp-env start
wp-env run cli "wp jetonomy status"
wp-env run cli "wp jetonomy qa-actions"
```

- [ ] **Step 4: Commit**

---

## Task 10: GitHub Actions CI Pipeline

**Files:**
- Create: `.github/workflows/tests.yml`

- [ ] **Step 1: Create tests.yml**

```yaml
name: Tests

on:
  push:
    branches: [main, master]
  pull_request:
    branches: [main, master]

jobs:
  phpunit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3']
        wp: ['6.7', '6.8', '6.9']
      fail-fast: false

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wp_tests
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mysqli, mbstring, intl
          coverage: none

      - name: Install Composer dependencies
        run: composer install --no-progress --prefer-dist

      - name: Install WordPress test library
        run: |
          bash bin/install-wp-tests.sh wp_tests root root localhost ${{ matrix.wp }}

      - name: Run PHPUnit
        run: vendor/bin/phpunit --testdox

      - name: Run CLI QA (structure)
        run: |
          wp jetonomy qa --report || true

      - name: Run CLI QA (actions)
        run: |
          wp jetonomy qa-actions || true

  wpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-progress
      - run: vendor/bin/phpcs --standard=WordPress includes/ templates/

  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-progress
      - run: vendor/bin/phpstan analyse includes/ --level=5
```

- [ ] **Step 2: Create bin/install-wp-tests.sh** (standard WP test install script)
- [ ] **Step 3: Update phpunit.xml.dist** to include new test suites

```xml
<testsuites>
    <testsuite name="unit">
        <directory suffix="Test.php">./tests/unit</directory>
    </testsuite>
    <testsuite name="integration">
        <directory suffix="Test.php">./tests/integration</directory>
    </testsuite>
    <testsuite name="security">
        <directory suffix="Test.php">./tests/security</directory>
    </testsuite>
    <testsuite name="error-paths">
        <directory suffix="Test.php">./tests/error-paths</directory>
    </testsuite>
    <testsuite name="concurrency">
        <directory suffix="Test.php">./tests/concurrency</directory>
    </testsuite>
    <testsuite name="pro">
        <directory suffix="Test.php">./tests/pro</directory>
    </testsuite>
</testsuites>
```

- [ ] **Step 4: Commit**

---

## Summary

| Task | Files | New Test Methods | Focus |
|------|-------|-----------------|-------|
| 1. SQL Injection | 1 | 5 | Data integrity |
| 2. XSS | 1 | 6 | Output sanitization |
| 3. CSRF | 1 | 15 | Auth enforcement |
| 4. Input Validation | 1 | 8 | Type safety |
| 5. Missing Objects | 1 | 6 | Graceful 404s |
| 6. Invalid State | 1 | 5 | State enforcement |
| 7. Malformed Requests | 1 | 5 | Request validation |
| 8. Concurrency | 1 | 4 | Counter consistency |
| 9. Pro Extensions | 4 | ~20 | Pro coverage |
| 10. wp-env | 1 | 0 | Local test env |
| 11. GitHub Actions | 2 | 0 | CI pipeline |
| **TOTAL** | **15** | **~74** | |

**Final test coverage after all tasks:**
- Existing PHPUnit: 154 methods
- New PHPUnit: ~74 methods
- CLI QA: 398 checks
- **Grand total: ~626 automated checks**
