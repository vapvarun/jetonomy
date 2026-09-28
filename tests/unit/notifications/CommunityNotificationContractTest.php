<?php
/**
 * The community notification contract: the payload Jetonomy hands a host
 * plugin (BuddyNext) as the last argument of jetonomy_notification_created,
 * the declared types, the visibility answer, and the removal hook.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit\Notifications;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Notifications\Community_Notification_Contract;
use Jetonomy\Notifications\Notifier;

class CommunityNotificationContractTest extends WP_UnitTestCase {

	private int $recipient;
	private int $actor;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->recipient = self::factory()->user->create();
		$this->actor     = self::factory()->user->create();
	}

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_community_notification_types' );
		remove_all_filters( 'jetonomy_community_notification_visible' );
		remove_all_actions( 'jetonomy_notification_created' );
		remove_all_actions( 'jetonomy_community_notification_removed' );
		parent::tear_down();
	}

	public function test_payload_carries_the_contract_shape(): void {
		$captured = null;
		add_action(
			'jetonomy_notification_created',
			static function ( ...$args ) use ( &$captured ): void {
				$captured = end( $args );
			},
			10,
			8
		);

		Notifier::emit_notification_created(
			42,
			$this->recipient,
			$this->actor,
			'reply_to_post',
			'reply',
			1153,
			'Aisha replied to your post "Welcome thread"',
			'https://example.test/community/s/general/t/welcome/#reply-1153'
		);

		$this->assertIsArray( $captured );
		$this->assertSame( $this->recipient, $captured['recipient_id'] );
		$this->assertSame( 'reply_to_post', $captured['type'] );
		$this->assertSame( $this->actor, $captured['actor_id'] );
		$this->assertSame( 'reply', $captured['object_type'] );
		$this->assertSame( 1153, $captured['object_id'] );
		$this->assertSame( 'Aisha replied to your post "Welcome thread"', $captured['message'] );
		$this->assertSame( 'https://example.test/community/s/general/t/welcome/#reply-1153', $captured['url'] );
		$this->assertSame( 'reply_to_post_1153', $captured['group_key'] );
		$this->assertSame( 42, $captured['notification_id'] );
	}

	public function test_payload_resolves_a_missing_url_from_the_object(): void {
		$cat_id   = Category::create( array( 'name' => 'CNC', 'slug' => 'cnc-' . uniqid() ) );
		$space_id = Space::create(
			array(
				'title'       => 'CNC Space',
				'slug'        => 'cnc-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
			)
		);
		$post_id = Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => $this->recipient,
				'title'     => 'CNC Post',
				'slug'      => 'cnc-post-' . uniqid(),
				'content'   => '<p>Body</p>',
			)
		);

		$payload = Community_Notification_Contract::payload( 1, $this->recipient, $this->actor, 'flag_resolved', 'post', $post_id, 'Your report was reviewed.', '' );

		$this->assertNotSame( '', $payload['url'], 'a caller that never resolved a URL still gets one' );
		$this->assertStringContainsString( 'cnc-space', $payload['url'] );
	}

	public function test_payload_is_empty_when_actor_notifies_themself(): void {
		$payload = Community_Notification_Contract::payload( 1, $this->recipient, $this->recipient, 'reply_to_post', 'reply', 5, 'Message', 'https://example.test/' );
		$this->assertSame( array(), $payload );
	}

	public function test_old_seven_arg_listener_is_unaffected(): void {
		$args_seen = null;
		add_action(
			'jetonomy_notification_created',
			static function ( $notification_id, $user_id, $type, $object_type, $object_id, $message, $url ) use ( &$args_seen ): void {
				$args_seen = func_get_args();
			},
			10,
			7
		);

		Notifier::emit_notification_created( 1, $this->recipient, $this->actor, 'mention', 'post', 9, 'You were mentioned', 'https://example.test/' );

		$this->assertCount( 7, $args_seen, 'a 7-arg listener never receives the contract payload' );
	}

	public function test_types_filter_declares_every_existing_notification_type(): void {
		$types = apply_filters( 'jetonomy_community_notification_types', array() );

		foreach ( array( 'new_post_in_sub', 'reply_to_post', 'reply_to_reply', 'vote_on_post', 'reaction', 'flag_resolved', 'accepted_answer', 'idea_status_changed', 'badge_earned', 'moderation', 'join_request_result', 'join_request', 'mention' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $types, "{$slug} must be declared" );
			$this->assertNotSame( '', $types[ $slug ]['label'] );
		}
	}

	public function test_visibility_hides_trashed_and_banned_actor(): void {
		$cat_id   = Category::create( array( 'name' => 'CNV', 'slug' => 'cnv-' . uniqid() ) );
		$pub_id   = Space::create( array( 'title' => 'Public', 'slug' => 'cnv-pub-' . uniqid(), 'category_id' => $cat_id, 'visibility' => 'public' ) );

		$visible_post  = Post::create( array( 'space_id' => $pub_id, 'author_id' => $this->actor, 'title' => 'A', 'slug' => 'cnv-a-' . uniqid(), 'content' => '<p>A</p>' ) );
		$trashed_post  = Post::create( array( 'space_id' => $pub_id, 'author_id' => $this->actor, 'title' => 'B', 'slug' => 'cnv-b-' . uniqid(), 'content' => '<p>B</p>' ) );
		Post::update( $trashed_post, array( 'status' => 'trash' ) );
		$banned_author = self::factory()->user->create();
		$banned_post   = Post::create( array( 'space_id' => $pub_id, 'author_id' => $banned_author, 'title' => 'D', 'slug' => 'cnv-d-' . uniqid(), 'content' => '<p>D</p>' ) );
		Restriction::ban( $banned_author, 'global_ban', 1 );

		$targets = array(
			'ok'      => array( 'type' => 'reply_to_post', 'object_type' => 'post', 'object_id' => $visible_post, 'actor_id' => $this->actor ),
			'trashed' => array( 'type' => 'reply_to_post', 'object_type' => 'post', 'object_id' => $trashed_post, 'actor_id' => $this->actor ),
			'banned'  => array( 'type' => 'reply_to_post', 'object_type' => 'post', 'object_id' => $banned_post, 'actor_id' => $banned_author ),
			'vote_banned' => array( 'type' => 'vote_on_post', 'object_type' => 'post', 'object_id' => $visible_post, 'actor_id' => $banned_author ),
			'badge'   => array( 'type' => 'badge_earned', 'object_type' => 'badge', 'object_id' => 3, 'actor_id' => 0 ),
		);

		$visible = apply_filters(
			'jetonomy_community_notification_visible',
			array_fill_keys( array_keys( $targets ), true ),
			$this->recipient,
			$targets
		);

		$this->assertTrue( $visible['ok'] );
		$this->assertFalse( $visible['trashed'] );
		$this->assertFalse( $visible['banned'] );
		$this->assertFalse( $visible['vote_banned'], 'the ban is on the notification actor, not only the content author' );
		$this->assertTrue( $visible['badge'], 'a non-content type has nothing to hide behind' );
	}

	public function test_pro_badge_reaches_the_contract_through_the_notifier(): void {
		$captured = null;
		add_action(
			'jetonomy_notification_created',
			static function ( ...$args ) use ( &$captured ): void {
				$captured = end( $args );
			},
			10,
			8
		);

		( new Notifier() )->on_badge_earned( $this->recipient, 7, (object) array( 'name' => 'Helper' ) );

		$this->assertIsArray( $captured, 'a Pro badge fires the notification hook with the contract payload' );
		$this->assertSame( 'badge_earned', $captured['type'] );
		$this->assertSame( $this->recipient, $captured['recipient_id'] );
		$this->assertStringContainsString( 'Helper', $captured['message'] );
		$this->assertNotSame( '', $captured['url'], 'no deep link falls back to the member\'s profile' );
	}

	/** @return array{0:int,1:int,2:int} topic id, first reply id, second reply id (different authors). */
	private function topic_with_two_replies(): array {
		$cat_id   = Category::create( array( 'name' => 'CNG', 'slug' => 'cng-' . uniqid() ) );
		$space_id = Space::create( array( 'title' => 'G', 'slug' => 'cng-s-' . uniqid(), 'category_id' => $cat_id, 'visibility' => 'public' ) );
		$post_id  = Post::create( array( 'space_id' => $space_id, 'author_id' => $this->recipient, 'title' => 'Grouped topic', 'slug' => 'cng-p-' . uniqid(), 'content' => '<p>T</p>' ) );
		$other    = self::factory()->user->create();
		$r1       = Reply::create( array( 'post_id' => $post_id, 'author_id' => $this->actor, 'content' => '<p>1</p>' ) );
		$r2       = Reply::create( array( 'post_id' => $post_id, 'author_id' => $other, 'content' => '<p>2</p>' ) );
		return array( $post_id, $r1, $r2 );
	}

	public function test_replies_to_one_topic_share_one_group_anchored_on_the_topic(): void {
		list( $post_id, $r1, $r2 ) = $this->topic_with_two_replies();

		$a = Community_Notification_Contract::payload( 1, $this->recipient, $this->actor, 'reply_to_post', 'reply', $r1, 'X replied to your post "Grouped topic"', 'https://example.test/r1' );
		$b = Community_Notification_Contract::payload( 2, $this->recipient, 999, 'reply_to_post', 'reply', $r2, 'Y replied to your post "Grouped topic"', 'https://example.test/r2' );

		$this->assertSame( 'reply_to_post_' . $post_id, $a['group_key'] );
		$this->assertSame( $a['group_key'], $b['group_key'], 'two repliers, one group' );
		$this->assertSame( 'post', $a['object_type'], 'the row is about the topic, so its status decides visibility' );
		$this->assertSame( $post_id, $a['object_id'] );
		$this->assertSame( \Jetonomy\notification_deep_link( 'post', $post_id ), $a['url'], 'the link is the topic, which always exists' );
		$this->assertSame( '{actor} and {others} replied to your post "Grouped topic"', $a['message_grouped'] );
		$this->assertSame( 'X replied to your post "Grouped topic"', $a['message'], 'the single-row wording is untouched' );
	}

	public function test_a_subscriber_gets_the_replied_in_wording_and_reply_to_reply_stays_per_reply(): void {
		list( $post_id, $r1 ) = $this->topic_with_two_replies();
		$subscriber           = self::factory()->user->create();

		$sub = Community_Notification_Contract::payload( 3, $subscriber, $this->actor, 'reply_to_post', 'reply', $r1, 'X replied in "Grouped topic"', 'https://example.test/r1' );
		$this->assertSame( '{actor} and {others} replied in "Grouped topic"', $sub['message_grouped'] );

		$rtr = Community_Notification_Contract::payload( 4, $this->recipient, $this->actor, 'reply_to_reply', 'reply', $r1, 'X replied to you', 'https://example.test/r1' );
		$this->assertSame( 'reply_to_reply_' . $r1, $rtr['group_key'], 'the recipient owns that one reply' );
		$this->assertSame( 'reply', $rtr['object_type'] );
		$this->assertArrayNotHasKey( 'message_grouped', $rtr );
	}

	public function test_an_anonymous_actor_reaches_the_host_as_nobody_and_is_not_grouped(): void {
		list( , $r1 ) = $this->topic_with_two_replies();
		$captured     = null;
		add_action(
			'jetonomy_notification_created',
			static function ( ...$args ) use ( &$captured ): void {
				$captured = end( $args );
			},
			10,
			8
		);

		// Through the real door, so a caller that forgets the flag fails here.
		Notifier::emit_notification_created( 5, $this->recipient, $this->actor, 'reply_to_post', 'reply', $r1, 'Anonymous replied to your post "Grouped topic"', 'https://example.test/r1', true );

		$this->assertSame( 0, $captured['actor_id'], 'the host names a member from actor_id, so an anonymous one is 0' );
		$this->assertSame( 'Anonymous replied to your post "Grouped topic"', $captured['message'] );
		$this->assertSame( 'reply', $captured['object_type'], 'stays per reply: a merged row would name its newest actor' );
		$this->assertSame( $r1, $captured['object_id'] );
		$this->assertSame( 'reply_to_post_' . $r1, $captured['group_key'] );
		$this->assertArrayNotHasKey( 'message_grouped', $captured );
	}

	public function test_an_anonymous_actor_who_is_the_recipient_still_gets_no_row(): void {
		$this->assertSame( array(), Community_Notification_Contract::payload( 6, $this->recipient, $this->recipient, 'reply_to_post', 'reply', 1, 'Anonymous replied', 'https://example.test/', true ), 'the self-notify guard reads the real actor' );
	}

	public function test_removal_fires_on_permanent_delete_only(): void {
		$removed = array();
		add_action(
			'jetonomy_community_notification_removed',
			static function ( $object_type, $object_id ) use ( &$removed ): void {
				$removed[] = array( $object_type, $object_id );
			},
			10,
			2
		);

		$cat_id  = Category::create( array( 'name' => 'CRM', 'slug' => 'crm-' . uniqid() ) );
		$space_id = Space::create( array( 'title' => 'CRM', 'slug' => 'crm-' . uniqid(), 'category_id' => $cat_id, 'visibility' => 'public' ) );
		$post_id = Post::create( array( 'space_id' => $space_id, 'author_id' => $this->actor, 'title' => 'E', 'slug' => 'crm-e-' . uniqid(), 'content' => '<p>E</p>' ) );
		$reply_id = Reply::create( array( 'post_id' => $post_id, 'author_id' => $this->actor, 'content' => '<p>R</p>', 'status' => 'publish' ) );

		// Trashing is a status change, not a removal — must not fire the hook.
		Post::update( $post_id, array( 'status' => 'trash' ) );
		$this->assertSame( array(), $removed, 'trash is visibility, not removal' );

		Reply::delete( $reply_id );
		Post::delete( $post_id );

		$this->assertContains( array( 'reply', $reply_id ), $removed );
		$this->assertContains( array( 'post', $post_id ), $removed );
	}
}
