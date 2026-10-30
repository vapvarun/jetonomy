<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * A member a rule admits to a private space (no roster row) sees that space's
 * notifications, as they already see its follows and bookmarks.
 *
 * Found by the 2.0.7 release walk (C.gating.access-ends-by-itself .13): the
 * notification row existed, but the list and unread count read space access
 * from the roster only and hid it.
 */
class RuleAdmittedNotificationsTest extends WP_UnitTestCase {

	public function test_rule_admitted_member_sees_notifications_from_the_space(): void {
		Schema::create_tables();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$actor  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cat    = Category::create( array( 'name' => 'RAN', 'slug' => 'ran-' . uniqid() ) );
		$space  = Space::create( array( 'title' => 'RAN', 'slug' => 'ran-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		AccessRule::create( array( 'space_id' => $space, 'rule_type' => 'role', 'rule_value' => 'subscriber', 'grants' => 'participate' ) );
		$post = Post::create( array( 'space_id' => $space, 'author_id' => $member, 'title' => 'Mine', 'content' => 'Body' ) );

		Notification::create( array(
			'user_id'     => $member,
			'actor_id'    => $actor,
			'type'        => 'reply_to_post',
			'object_type' => 'post',
			'object_id'   => $post,
		) );

		$this->assertCount( 1, Notification::list_for_user( $member ), 'The notification is listed.' );
		$this->assertSame( 1, Notification::unread_count( $member ), 'It counts as unread.' );
	}
}
