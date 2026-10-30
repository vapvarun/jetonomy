<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

/**
 * A profile's Replies tab must not list replies under a trashed topic.
 *
 * Trashing a topic leaves its replies 'publish' (they come back with the topic),
 * so Reply::list_by_user() has to check the parent topic's status as well.
 * Found by the 2.0.7 release walk (C.member.post-delete .8 / .17).
 */
class ProfileRepliesTrashedTopicTest extends WP_UnitTestCase {

	public function test_replies_under_a_trashed_topic_leave_the_profile_list(): void {
		Schema::create_tables();
		$author   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$replier  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cat_id   = Category::create( array( 'name' => 'Trash Cat', 'slug' => 'trash-cat-' . uniqid() ) );
		$space_id = Space::create( array(
			'title'       => 'Trash Space',
			'slug'        => 'trash-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		) );
		$post_id  = Post::create( array(
			'space_id'  => $space_id,
			'author_id' => $author,
			'title'     => 'Topic that gets trashed',
			'content'   => 'Body',
		) );
		$reply_id = Reply::create( array(
			'post_id'   => $post_id,
			'author_id' => $replier,
			'content'   => 'A reply under it',
		) );

		wp_set_current_user( 0 );
		$this->assertContains( $reply_id, array_map( 'intval', wp_list_pluck( Reply::list_by_user( $replier ), 'id' ) ), 'A reply under a published topic is listed.' );

		Post::update( $post_id, array( 'status' => 'trash' ) );

		$this->assertNotContains( $reply_id, array_map( 'intval', wp_list_pluck( Reply::list_by_user( $replier ), 'id' ) ), 'A reply under a trashed topic is not listed.' );
	}
}
