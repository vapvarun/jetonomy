<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Bookmark;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\ReadStatus;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Tag;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Vote;
use Jetonomy\Moderation\Moderation_Service;
use function Jetonomy\table;

/**
 * Hard delete + trash lifecycle for topics and replies.
 *
 * Basecamp 10344032754: Post::delete() removed the topic row and nothing
 * else, so every hard-delete path (CLI, harness, admin) left the topic's
 * replies - and every vote, flag, bookmark, tag link and subscription - behind.
 * Basecamp 10335997408: trashed content had no permanent delete anywhere and
 * no restore outside one wp-admin row action.
 *
 * Every cascade test also asserts a bystander topic survives: a cascade that
 * deletes too much destroys other members' words.
 */
class ContentHardDeleteTest extends WP_UnitTestCase {

	private int $space_id;
	private int $author_id;
	private int $replier_id;
	private int $mod_id;
	private int $bystander_post;
	private int $bystander_reply;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$suffix         = uniqid( 'chd_', true );
		$cat            = (int) Category::create(
			[
				'name' => 'Hard delete',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id = (int) Space::create(
			[
				'category_id' => $cat,
				'title'       => 'Hard delete space',
				'slug'        => 's-' . $suffix,
				'visibility'  => 'public',
			],
			0
		);

		$this->author_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->replier_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->mod_id     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		foreach ( [ $this->author_id, $this->replier_id, $this->mod_id ] as $uid ) {
			UserProfile::find_or_create( $uid );
		}
		SpaceMember::add( $this->space_id, $this->author_id, 'member' );
		SpaceMember::add( $this->space_id, $this->replier_id, 'member' );
		SpaceMember::add( $this->space_id, $this->mod_id, 'moderator' );

		$this->bystander_post  = $this->topic( 'Bystander' );
		$this->bystander_reply = $this->reply( $this->bystander_post, $this->replier_id );
		Vote::cast( $this->mod_id, 'reply', $this->bystander_reply, 1 );
	}

	private function topic( string $title ): int {
		return (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => $title,
				'content'   => '<p>body</p>',
				'status'    => 'publish',
			]
		);
	}

	private function reply( int $post_id, int $author_id ): int {
		return (int) Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => $author_id,
				'content'   => '<p>reply</p>',
				'status'    => 'publish',
			]
		);
	}

	private function rows( string $t, string $where ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . table( $t ) . ' WHERE ' . $where );
	}

	private function reply_count( int $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT reply_count FROM ' . table( 'user_profiles' ) . ' WHERE user_id = %d', $uid ) );
	}

	public function test_post_delete_removes_replies_and_every_dependent_row(): void {
		$post_id = $this->topic( 'Doomed' );
		$r1      = $this->reply( $post_id, $this->replier_id );
		$r2      = $this->reply( $post_id, $this->replier_id );

		Vote::cast( $this->mod_id, 'post', $post_id, 1 );
		Vote::cast( $this->mod_id, 'reply', $r1, 1 );
		Bookmark::toggle( $this->mod_id, $post_id );
		$tag_id = Tag::find_or_create( 'chd-tag-' . wp_generate_password( 6, false ) );
		Tag::attach_to_post( $post_id, $tag_id );
		Subscription::subscribe( $this->mod_id, 'post', $post_id );
		ReadStatus::mark_read( $this->mod_id, $post_id, $r2 );
		Flag::create(
			[
				'reporter_id' => $this->mod_id,
				'object_type' => 'reply',
				'object_id'   => $r2,
				'reason'      => 'spam',
				'status'      => 'pending',
			]
		);

		$replies_before = $this->reply_count( $this->replier_id );
		$fired          = [];
		$spy            = static function ( $id ) use ( &$fired ) {
			$fired[] = (int) $id;
		};
		add_action( 'jetonomy_after_delete_reply', $spy );

		$this->assertTrue( Post::delete( $post_id ) );
		remove_action( 'jetonomy_after_delete_reply', $spy );

		$this->assertNull( Post::find( $post_id ) );
		$this->assertSame( 0, $this->rows( 'replies', "post_id = {$post_id}" ), 'replies left behind' );
		$this->assertSame( 0, $this->rows( 'votes', "(object_type = 'post' AND object_id = {$post_id}) OR (object_type = 'reply' AND object_id IN ({$r1},{$r2}))" ) );
		$this->assertSame( 0, $this->rows( 'bookmarks', "post_id = {$post_id}" ) );
		$this->assertSame( 0, $this->rows( 'post_tags', "post_id = {$post_id}" ) );
		$this->assertSame( 0, (int) Tag::find( $tag_id )->post_count, 'tag post_count not decremented' );
		$this->assertSame( 0, $this->rows( 'subscriptions', "object_type = 'post' AND object_id = {$post_id}" ) );
		$this->assertSame( 0, $this->rows( 'read_status', "post_id = {$post_id}" ) );
		$this->assertSame( 0, $this->rows( 'flags', "object_type = 'reply' AND object_id = {$r2}" ) );

		// Counters and the per-reply contract other plugins listen to.
		$this->assertSame( $replies_before - 2, $this->reply_count( $this->replier_id ) );
		sort( $fired );
		$this->assertSame( [ $r1, $r2 ], $fired );

		// Bystander untouched.
		$this->assertNotNull( Post::find( $this->bystander_post ) );
		$this->assertNotNull( Reply::find( $this->bystander_reply ) );
		$this->assertSame( 1, $this->rows( 'votes', "object_type = 'reply' AND object_id = {$this->bystander_reply}" ) );
	}

	public function test_post_delete_drains_more_replies_than_one_batch(): void {
		global $wpdb;
		$post_id = $this->topic( 'Big' );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$values  = [];
		for ( $i = 0; $i < 501; $i++ ) {
			$values[] = $wpdb->prepare( '(%d,%d,%s,%s,%s,%s)', $post_id, $this->replier_id, 'r', 'r', 'publish', $now );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'INSERT INTO ' . table( 'replies' ) . ' (post_id, author_id, content, content_plain, status, created_at) VALUES ' . implode( ',', $values ) );

		$fires = 0;
		$spy   = static function () use ( &$fires ) {
			++$fires;
		};
		add_action( 'jetonomy_after_delete_reply', $spy );
		Post::delete( $post_id );
		remove_action( 'jetonomy_after_delete_reply', $spy );

		$this->assertSame( 0, $this->rows( 'replies', "post_id = {$post_id}" ) );
		$this->assertSame( 501, $fires );
		$this->assertNotNull( Reply::find( $this->bystander_reply ) );
	}

	public function test_reply_delete_removes_its_votes_and_flags_but_keeps_child_replies(): void {
		$post_id = $this->topic( 'Thread' );
		$parent  = $this->reply( $post_id, $this->replier_id );
		$child   = (int) Reply::create(
			[
				'post_id'   => $post_id,
				'parent_id' => $parent,
				'author_id' => $this->author_id,
				'content'   => '<p>child</p>',
				'status'    => 'publish',
			]
		);
		Vote::cast( $this->mod_id, 'reply', $parent, 1 );
		Flag::create(
			[
				'reporter_id' => $this->mod_id,
				'object_type' => 'reply',
				'object_id'   => $parent,
				'reason'      => 'spam',
				'status'      => 'pending',
			]
		);

		$this->assertTrue( Reply::delete( $parent ) );

		$this->assertSame( 0, $this->rows( 'votes', "object_type = 'reply' AND object_id = {$parent}" ) );
		$this->assertSame( 0, $this->rows( 'flags', "object_type = 'reply' AND object_id = {$parent}" ) );
		$this->assertNotNull( Reply::find( $child ), 'another member\'s child reply must survive' );
	}

	/**
	 * DELETE ?force=true is moderator-only; the author can only trash.
	 * Restore is the space-scoped approve route.
	 */
	public function test_rest_force_delete_and_restore_lifecycle(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$call = static function ( int $uid, string $method, string $route, array $params = [] ): int {
			wp_set_current_user( $uid );
			$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
			return rest_do_request( $req )->get_status();
		};

		$post_id  = $this->topic( 'Lifecycle' );
		$reply_id = $this->reply( $post_id, $this->replier_id );

		$this->assertSame( 403, $call( $this->author_id, 'DELETE', "/posts/{$post_id}", [ 'force' => true ] ) );
		$this->assertSame( 200, $call( $this->author_id, 'DELETE', "/posts/{$post_id}" ) );
		$this->assertSame( 'trash', Post::find( $post_id )->status );

		$this->assertSame( 403, $call( $this->replier_id, 'POST', "/spaces/{$this->space_id}/moderation/approve/post/{$post_id}" ) );
		$this->assertSame( 200, $call( $this->mod_id, 'POST', "/spaces/{$this->space_id}/moderation/approve/post/{$post_id}" ) );
		$this->assertSame( 'publish', Post::find( $post_id )->status );

		$this->assertSame( 403, $call( $this->replier_id, 'DELETE', "/replies/{$reply_id}", [ 'force' => true ] ) );
		$this->assertSame( 200, $call( $this->mod_id, 'DELETE', "/posts/{$post_id}", [ 'force' => true ] ) );
		$this->assertNull( Post::find( $post_id ) );
		$this->assertNull( Reply::find( $reply_id ) );

		$wp_rest_server = null;
	}

	/**
	 * wp-admin "Delete Permanently" (row + bulk): admin-only, and it goes
	 * through the model so the topic takes its replies with it.
	 */
	public function test_admin_ajax_delete_permanently(): void {
		$action = 'jetonomy_delete_content_permanently';
		new \Jetonomy\Admin\Ajax\Content_Handler();
		$this->assertNotFalse( has_action( 'wp_ajax_' . $action ) );

		$post_id  = $this->topic( 'Admin purge' );
		$reply_id = $this->reply( $post_id, $this->replier_id );
		Post::update( $post_id, [ 'status' => 'trash' ] );

		$run = function ( int $uid ) use ( $post_id, $action ): array {
			wp_set_current_user( $uid );
			$_POST    = [
				'nonce' => wp_create_nonce( 'jetonomy_admin' ),
				'type'  => 'post',
				'ids'   => [ (string) $post_id ],
			];
			$_REQUEST = $_POST;
			$die      = static function () {
				return static function () {
					throw new \RuntimeException( 'ajax-die' );
				};
			};
			add_filter( 'wp_doing_ajax', '__return_true' );
			add_filter( 'wp_die_ajax_handler', $die );
			ob_start();
			try {
				do_action( 'wp_ajax_' . $action );
			} catch ( \RuntimeException $e ) {
				unset( $e );
			}
			$out = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
			$_POST    = [];
			$_REQUEST = [];
			return (array) json_decode( $out, true );
		};

		// The space moderator has no wp-admin capability: refused, nothing deleted.
		$denied = $run( $this->mod_id );
		$this->assertFalse( $denied['success'] ?? true );
		$this->assertNotNull( Post::find( $post_id ) );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $admin )->add_cap( 'jetonomy_manage_settings' );
		$ok = $run( $admin );

		$this->assertTrue( $ok['success'] ?? false );
		$this->assertSame( 1, (int) ( $ok['data']['deleted'] ?? 0 ) );
		$this->assertNull( Post::find( $post_id ) );
		$this->assertNull( Reply::find( $reply_id ) );
	}

	public function test_trash_queue_is_scoped_like_approvals(): void {
		$post_id = $this->topic( 'Trashed' );
		Post::update( $post_id, [ 'status' => 'trash' ] );

		$this->assertSame( 1, Moderation_Service::count_trashed( $this->mod_id, 'post', $this->space_id ) );
		$ids = array_map( static fn( $p ) => (int) $p->id, Moderation_Service::list_trashed( $this->mod_id, 'post', $this->space_id ) );
		$this->assertSame( [ $post_id ], $ids );

		// A plain member sees nothing - an empty scope, never "every space".
		$this->assertSame( 0, Moderation_Service::count_trashed( $this->replier_id, 'post', $this->space_id ) );
		$this->assertSame( [], Moderation_Service::list_trashed( $this->replier_id, 'post' ) );
	}
}
