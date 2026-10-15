<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Flag;
use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Trust\Trust_Levels;

/**
 * Site moderators act only in spaces they can read, on every moderation path.
 *
 * Permission_Engine already held this rule for editing and pinning, but the
 * moderation layer granted jetonomy_moderate everywhere: an Editor who was not
 * a member of a private space could trash its topics by id, read its pending
 * queue and flags, and any member could flag content they could not see (each
 * flag docking the author's reputation). One rule now covers acting, listing
 * (rows and totals), viewing a topic's flags and creating a flag.
 */
class StaffReachTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $private_space;
	private int $public_space;
	private int $editor;
	private int $member;
	private int $outsider;
	private int $private_post;
	private int $public_post;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		Flag::reset_memo();
		\Jetonomy\Cache::flush();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$cat                 = Category::create(
			array(
				'name' => 'Reach',
				'slug' => 'reach-' . uniqid(),
			)
		);
		$this->private_space = (int) Space::create(
			array(
				'title'       => 'Advisory board',
				'slug'        => 'advisory-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'private',
				'join_policy' => 'invite',
				'type'        => 'forum',
			)
		);
		$this->public_space  = (int) Space::create(
			array(
				'title'       => 'General',
				'slug'        => 'general-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $this->editor )->add_cap( 'jetonomy_moderate' );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $this->private_space, $this->member, 'member' );
		SpaceMember::add( $this->public_space, $this->member, 'member' );
		SpaceMember::add( $this->public_space, $this->outsider, 'member' );

		$this->private_post = (int) Post::create(
			array(
				'space_id'  => $this->private_space,
				'author_id' => $this->member,
				'title'     => 'Board minutes',
				'content'   => 'Confidential',
				'status'    => 'pending',
			)
		);
		$this->public_post  = (int) Post::create(
			array(
				'space_id'  => $this->public_space,
				'author_id' => $this->member,
				'title'     => 'Welcome',
				'content'   => 'Hello',
				'status'    => 'pending',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_moderator_acts_only_where_they_can_read(): void {
		$this->assertFalse( Moderation_Permissions::can_act_on_object( $this->editor, 'post', $this->private_post ), 'private space, not a member' );
		$this->assertSame( array( $this->private_space ), Moderation_Permissions::unreadable_space_ids( $this->editor ) );
		$this->assertTrue( Moderation_Permissions::can_act_on_object( $this->editor, 'post', $this->public_post ) );
		$this->assertFalse( Moderation_Permissions::can_view_space_queue( $this->editor, $this->private_space ) );
		$this->assertTrue( Moderation_Permissions::can_act_on_object( 1, 'post', $this->private_post ), 'administrators reach every space' );

		SpaceMember::add( $this->private_space, $this->editor, 'member' );
		\Jetonomy\Cache::flush();
		$this->assertTrue( Moderation_Permissions::can_act_on_object( $this->editor, 'post', $this->private_post ), 'membership restores reach' );
	}

	public function test_every_read_denial_is_covered_not_only_space_visibility(): void {
		// A public space inside a hidden category is concealed by its category.
		$hidden_cat = Category::create(
			array(
				'name'       => 'Staging',
				'slug'       => 'staging-' . uniqid(),
				'visibility' => 'hidden',
			)
		);
		$concealed  = (int) Space::create(
			array(
				'title'       => 'Launch prep',
				'slug'        => 'launch-' . uniqid(),
				'category_id' => $hidden_cat,
				'visibility'  => 'public',
				'type'        => 'forum',
			)
		);
		\Jetonomy\Cache::flush();
		if ( ! \Jetonomy\Permissions\Permission_Engine::can( $this->editor, 'read', $concealed ) ) {
			$this->assertContains( $concealed, Moderation_Permissions::unreadable_space_ids( $this->editor ), 'category concealment' );
		}

		// A moderator banned from one public space cannot read or moderate it.
		\Jetonomy\Models\Restriction::ban( $this->editor, 'space_ban', 1, $this->public_space );
		\Jetonomy\Cache::flush();
		$this->assertContains( $this->public_space, Moderation_Permissions::unreadable_space_ids( $this->editor ), 'space ban' );
		$this->assertFalse( Moderation_Permissions::can_act_on_object( $this->editor, 'post', $this->public_post ) );
		$this->assertSame( 0, Post::count_by_status( array( 'pending' ), Moderation_Permissions::staff_scope( $this->editor ) ), 'lists agree with the per-object check' );
	}

	public function test_queue_rows_and_totals_leave_out_unreadable_spaces(): void {
		$scope = Moderation_Permissions::staff_scope( $this->editor );
		$ids   = array_map( 'intval', wp_list_pluck( Post::list_by_status( array( 'pending' ), 50, 0, $scope ), 'id' ) );
		$this->assertContains( $this->public_post, $ids );
		$this->assertNotContains( $this->private_post, $ids );
		$this->assertSame( 1, Post::count_by_status( array( 'pending' ), $scope ), 'the total must not count what the list hides' );
		$this->assertNull( Moderation_Permissions::staff_scope( 1 ) );
		$this->assertSame( 2, Post::count_by_status( array( 'pending' ), Moderation_Permissions::staff_scope( 1 ) ) );

		$reply = (int) Reply::create(
			array(
				'post_id'   => $this->private_post,
				'author_id' => $this->member,
				'content'   => 'Held reply',
				'status'    => 'pending',
			)
		);
		$this->assertNotContains( $reply, array_map( 'intval', wp_list_pluck( Reply::list_by_status( array( 'pending' ), 50, 0, $scope ), 'id' ) ) );
		$this->assertSame( 0, Reply::count_by_status( array( 'pending' ), $scope ) );
	}

	public function test_flag_lists_leave_out_unreadable_spaces_but_keep_member_flags(): void {
		foreach ( array( $this->private_post, $this->public_post ) as $pid ) {
			Flag::create(
				array(
					'reporter_id' => $this->outsider,
					'object_type' => 'post',
					'object_id'   => $pid,
					'reason'      => 'spam',
				)
			);
		}
		Flag::create(
			array(
				'reporter_id' => $this->outsider,
				'object_type' => 'user',
				'object_id'   => $this->member,
				'reason'      => 'spam',
			)
		);
		$skip = Moderation_Permissions::unreadable_space_ids( $this->editor );

		$objects = array_map( static fn( $f ) => $f->object_type . ':' . $f->object_id, Flag::list_pending( 0, 0, $skip ) );
		$this->assertNotContains( 'post:' . $this->private_post, $objects );
		$this->assertContains( 'post:' . $this->public_post, $objects );
		$this->assertContains( 'user:' . $this->member, $objects, 'member flags have no space and stay visible' );
		$this->assertSame( 2, Flag::count_pending( $skip ) );
		$this->assertSame( 2, Flag::count_by_status( 'pending', $skip ) );
		$this->assertCount( 2, Flag::list_by_status( 'pending', 50, 0, $skip ) );
		$this->assertSame( 3, Flag::count_pending(), 'no exclusion = unchanged query' );
	}

	public function test_member_cannot_flag_content_they_cannot_read(): void {
		Post::update( $this->private_post, array( 'status' => 'publish' ) );
		Post::update( $this->public_post, array( 'status' => 'publish' ) );
		wp_set_current_user( $this->outsider );

		$this->assertSame( 404, $this->report( 'post', $this->private_post ), 'private space: same answer as a missing topic' );
		$this->assertSame( 0, Flag::count_pending() );
		$this->assertSame( 201, $this->report( 'post', $this->public_post ) );

		Post::update( $this->public_post, array( 'status' => 'trash' ) );
		wp_set_current_user( $this->member );
		$this->assertSame( 404, $this->report( 'post', $this->public_post ), 'trashed content cannot be flagged' );
	}

	public function test_user_managers_cannot_raise_each_other(): void {
		$a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $a )->add_cap( 'jetonomy_manage_users' );
		get_user_by( 'id', $b )->add_cap( 'jetonomy_manage_users' );

		$result = Trust_Levels::set_manually( $b, 3, $a );
		$this->assertWPError( $result );
		$this->assertSame( 'jetonomy_trust_peer', $result->get_error_code() );
		$this->assertSame( 2, Trust_Levels::set_manually( $this->outsider, 2, $a ), 'ordinary members can still be changed' );
	}

	private function report( string $type, int $id ): int {
		$req = new WP_REST_Request( 'POST', '/jetonomy/v1/flags' );
		$req->set_param( 'object_type', $type );
		$req->set_param( 'object_id', $id );
		$req->set_param( 'reason', 'spam' );
		return $this->server->dispatch( $req )->get_status();
	}
}
