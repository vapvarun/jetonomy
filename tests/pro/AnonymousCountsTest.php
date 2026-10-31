<?php
/**
 * Anonymous topics and replies must not be counted on any surface that shows
 * a member's counts: the stored counters keep them (trust levels read them),
 * the displayed ones drop them, or "3 topics" beside a stream of 2 reveals
 * who wrote the anonymous one.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;

class AnonymousCountsTest extends WP_UnitTestCase {

	private int $author;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->author = self::factory()->user->create();
		UserProfile::find_or_create( $this->author );
		$cat   = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );

		$ids = array();
		foreach ( array( 0, 0, 1 ) as $anon ) {
			$ids[] = Post::create( array( 'space_id' => $space, 'author_id' => $this->author, 'title' => 'T', 'content' => 'c' ) );
			if ( $anon ) {
				Post::update( end( $ids ), array( 'is_anonymous' => 1 ) );
			}
		}
		foreach ( array( 1, 1 ) as $anon ) {
			$rid = Reply::create( array( 'post_id' => $ids[0], 'author_id' => $this->author, 'content' => 'r' ) );
			Reply::update( $rid, array( 'is_anonymous' => $anon ) );
		}
	}

	public function test_stored_counters_keep_anonymous_work(): void {
		$p = UserProfile::find_by_user( $this->author );
		$this->assertSame( array( 3, 2 ), array( (int) $p->post_count, (int) $p->reply_count ) );
	}

	public function test_public_profile_drops_anonymous_items(): void {
		$p = UserProfile::find_public( $this->author );
		$this->assertSame( array( 2, 0 ), array( (int) $p->post_count, (int) $p->reply_count ) );
	}

	public function test_rest_user_and_leaderboard_drop_anonymous_items(): void {
		do_action( 'rest_api_init' );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/users/' . $this->author ) );
		$this->assertSame( 2, (int) $res->get_data()['post_count'] );
		$this->assertSame( 0, (int) $res->get_data()['reply_count'] );

		$rows = UserProfile::with_public_counts( array( UserProfile::find_by_user( $this->author ) ) );
		$this->assertSame( 2, (int) $rows[0]->post_count );

		foreach ( UserProfile::list_for_leaderboard( 'all', 1000, 0 ) as $row ) {
			if ( (int) $row->user_id === $this->author ) {
				$this->assertSame( 2, (int) $row->post_count );
				$this->assertSame( 0, (int) $row->reply_count );
			}
		}
	}
}
