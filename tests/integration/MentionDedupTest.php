<?php
/**
 * One notice per person per new topic or reply: someone @mentioned gets the
 * mention notice only, not also the reply / subscriber notice, and a subscribed
 * parent-reply author is told once. Before 2.0.3 each path notified on its own,
 * so a mentioned topic author got two notices and two emails.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\Subscription;

class MentionDedupTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->space = Space::create(
			array(
				'title'       => 'Dedup',
				'slug'        => 'dedup-' . uniqid(),
				'category_id' => Category::create( array( 'name' => 'D', 'slug' => 'd-' . uniqid() ) ),
				'visibility'  => 'public',
			)
		);
	}

	private function user( string $login ): int {
		return self::factory()->user->create( array( 'user_login' => $login, 'user_nicename' => $login ) );
	}

	/** @return array<int,int> notice count per recipient for one object. */
	private function notices( string $object_type, int $object_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}jt_notifications WHERE object_type = %s AND object_id = %d", $object_type, $object_id ) );
		return array_count_values( array_map( 'intval', $rows ) );
	}

	public function test_a_reply_tells_each_person_once(): void {
		$author     = $this->user( 'dd_author' );
		$parent_by  = $this->user( 'dd_parent' );
		$subscriber = $this->user( 'dd_sub' );
		$quiet_sub  = $this->user( 'dd_quiet' );
		$actor      = $this->user( 'dd_actor' );

		$post   = (int) Post::create( array( 'space_id' => $this->space, 'author_id' => $author, 'title' => 'Thread', 'content' => 'Body', 'status' => 'publish' ) );
		$parent = (int) Reply::create( array( 'post_id' => $post, 'author_id' => $parent_by, 'content' => 'First', 'status' => 'publish' ) );
		foreach ( array( $parent_by, $subscriber, $quiet_sub ) as $uid ) {
			Subscription::subscribe( $uid, 'post', $post );
		}

		$reply = (int) Reply::create(
			array(
				'post_id'   => $post,
				'parent_id' => $parent,
				'author_id' => $actor,
				'content'   => 'Agreed @dd_author and @dd_sub',
				'status'    => 'publish',
			)
		);
		do_action( 'jetonomy_after_create_reply', $reply, $post, null );

		$counts = $this->notices( 'reply', $reply );
		foreach ( array( 'topic author (mentioned)' => $author, 'parent author (subscribed)' => $parent_by, 'mentioned subscriber' => $subscriber, 'plain subscriber' => $quiet_sub ) as $who => $uid ) {
			$this->assertSame( 1, $counts[ $uid ] ?? 0, "{$who}: exactly one notice" );
		}
		$this->assertArrayNotHasKey( $actor, $counts );
	}

	public function test_a_new_topic_does_not_tell_a_mentioned_subscriber_twice(): void {
		$mentioned = $this->user( 'dd_spacesub' );
		$other     = $this->user( 'dd_other' );
		$actor     = $this->user( 'dd_poster' );
		Subscription::subscribe( $mentioned, 'space', $this->space );
		Subscription::subscribe( $other, 'space', $this->space );

		$post = (int) Post::create( array( 'space_id' => $this->space, 'author_id' => $actor, 'title' => 'News', 'content' => 'Look @dd_spacesub', 'status' => 'publish' ) );
		do_action( 'jetonomy_after_create_post', $post, $this->space, null );

		$counts = $this->notices( 'post', $post );
		$this->assertSame( 1, $counts[ $mentioned ] ?? 0, 'mentioned subscriber: the mention only' );
		$this->assertSame( 1, $counts[ $other ] ?? 0, 'other subscriber still hears about the topic' );
	}
}
