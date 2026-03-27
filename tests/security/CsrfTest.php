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
