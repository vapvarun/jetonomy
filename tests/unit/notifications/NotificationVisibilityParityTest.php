<?php
/**
 * The bell filter (Notification::targets_visible) and Jetonomy's own list
 * (Notification::list_for_user_with_targets, i.e. visibility_sql) are one rule
 * written twice. This runs both over the same rows and fails on any disagreement,
 * and pins the cases the two used to get wrong.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit\Notifications;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\BlockedUser;
use Jetonomy\Models\Category;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

class NotificationVisibilityParityTest extends WP_UnitTestCase {

	private int $viewer;
	private int $actor;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->viewer   = self::factory()->user->create();
		$this->actor    = self::factory()->user->create();
		$cat_id         = Category::create( array( 'name' => 'NVP', 'slug' => 'nvp-' . uniqid() ) );
		$this->space_id = Space::create( array( 'title' => 'NVP', 'slug' => 'nvp-s-' . uniqid(), 'category_id' => $cat_id, 'visibility' => 'private', 'join_policy' => 'approval' ) );
		// The viewer posts in this private space, so they are a member of it -
		// notifications are only shown while the viewer can read the space.
		SpaceMember::add( $this->space_id, $this->viewer, 'member' );
	}

	private function post( string $status = 'publish', ?int $author = null ): int {
		$id = Post::create( array( 'space_id' => $this->space_id, 'author_id' => $author ?? $this->viewer, 'title' => 'T', 'slug' => 'nvp-p-' . uniqid(), 'content' => '<p>T</p>' ) );
		if ( 'publish' !== $status ) {
			Post::update( $id, array( 'status' => $status ) );
		}
		return $id;
	}

	private function reply( int $post_id, string $status = 'publish' ): int {
		$id = Reply::create( array( 'post_id' => $post_id, 'author_id' => $this->actor, 'content' => '<p>R</p>' ) );
		if ( 'publish' !== $status ) {
			Reply::update( $id, array( 'status' => $status ) );
		}
		return $id;
	}

	/** @return array<string,array{type:string,object_type:string,object_id:int,actor_id:int}> */
	private function fixtures(): array {
		$live  = $this->post();
		$trash = $this->post( 'trash' );
		$other = self::factory()->user->create();
		return array(
			'reply_live'        => array( 'type' => 'reply_to_post', 'object_type' => 'reply', 'object_id' => $this->reply( $live ), 'actor_id' => $this->actor ),
			'reply_trashed'     => array( 'type' => 'reply_to_post', 'object_type' => 'reply', 'object_id' => $this->reply( $live, 'trash' ), 'actor_id' => $this->actor ),
			'reply_dead_parent' => array( 'type' => 'reply_to_post', 'object_type' => 'reply', 'object_id' => $this->reply( $trash ), 'actor_id' => $this->actor ),
			'post_live'         => array( 'type' => 'vote_on_post', 'object_type' => 'post', 'object_id' => $live, 'actor_id' => $this->actor ),
			'post_trashed'      => array( 'type' => 'vote_on_post', 'object_type' => 'post', 'object_id' => $trash, 'actor_id' => $this->actor ),
			'flag_resolved_dead' => array( 'type' => 'flag_resolved', 'object_type' => 'post', 'object_id' => $trash, 'actor_id' => $other ),
			'moderation_reply'  => array( 'type' => 'moderation', 'object_type' => 'reply', 'object_id' => $this->reply( $live, 'trash' ), 'actor_id' => $other ),
			'join_declined'     => array( 'type' => 'join_request_result', 'object_type' => 'space', 'object_id' => $this->space_id, 'actor_id' => $other ),
			'badge'             => array( 'type' => 'badge_earned', 'object_type' => 'badge', 'object_id' => 3, 'actor_id' => 0 ),
			'banned_actor_vote' => array( 'type' => 'vote_on_post', 'object_type' => 'post', 'object_id' => $live, 'actor_id' => self::factory()->user->create() ),
			'blocked_actor'     => array( 'type' => 'reaction', 'object_type' => 'post', 'object_id' => $live, 'actor_id' => self::factory()->user->create() ),
			'missing_target'    => array( 'type' => 'reply_to_post', 'object_type' => 'post', 'object_id' => 99999999, 'actor_id' => $this->actor ),
			'post_unreadable'   => array( 'type' => 'new_post_in_sub', 'object_type' => 'post', 'object_id' => $this->post_in_closed_space(), 'actor_id' => $this->actor ),
		);
	}

	/** A live topic in a private space the viewer is not a member of (Basecamp 10345420194). */
	private function post_in_closed_space(): int {
		$space = Space::create( array( 'title' => 'NVP closed', 'slug' => 'nvp-c-' . uniqid(), 'visibility' => 'private', 'join_policy' => 'approval' ) );
		return Post::create( array( 'space_id' => $space, 'author_id' => $this->actor, 'title' => 'C', 'slug' => 'nvp-c-p-' . uniqid(), 'content' => '<p>C</p>' ) );
	}

	public function test_bell_filter_and_notifications_list_agree_and_pin_the_fixed_cases(): void {
		$targets = $this->fixtures();
		Restriction::ban( $targets['banned_actor_vote']['actor_id'], 'global_ban', 1 );
		BlockedUser::block( $this->viewer, $targets['blocked_actor']['actor_id'] );

		$row_ids = array();
		foreach ( $targets as $key => $t ) {
			$row_ids[ $key ] = Notification::create(
				array(
					'user_id'     => $this->viewer,
					'actor_id'    => $t['actor_id'],
					'type'        => $t['type'],
					'object_type' => $t['object_type'],
					'object_id'   => $t['object_id'],
					'message'     => $key,
				)
			);
		}

		$in_list = array();
		foreach ( Notification::list_for_user_with_targets( $this->viewer, 100 ) as $row ) {
			$in_list[ $row->message ] = true;
		}
		$bell = Notification::targets_visible( $this->viewer, $targets );

		foreach ( $targets as $key => $unused ) {
			$this->assertSame( isset( $in_list[ $key ] ), $bell[ $key ], "bell and list disagree on '{$key}'" );
		}

		// The cases the first version got wrong.
		$this->assertTrue( $bell['flag_resolved_dead'], 'flag_resolved is exempt from the target-status rule' );
		$this->assertTrue( $bell['moderation_reply'], 'a removed reply\'s moderation notice stays visible to its author' );
		$this->assertTrue( $bell['join_declined'], 'a declined requester still sees the outcome of a private space' );
		$this->assertTrue( $bell['badge'] );
		$this->assertTrue( $bell['missing_target'], 'a target that resolves to nothing is left to the renderer' );
		$this->assertFalse( $bell['banned_actor_vote'], 'a globally banned actor is hidden even when the content author is fine' );
		$this->assertFalse( $bell['blocked_actor'], 'a block made after the notification was created still applies' );
		$this->assertFalse( $bell['reply_trashed'] );
		$this->assertFalse( $bell['post_trashed'] );
		$this->assertFalse( $bell['reply_dead_parent'] );
		$this->assertTrue( $bell['reply_live'] );
		$this->assertTrue( $bell['post_live'] );
		$this->assertFalse( $bell['post_unreadable'], 'a topic in a private space the viewer cannot read is hidden' );
	}
}
