<?php
/**
 * Notification read paths hide rows a tap could not open: an actor under an
 * active global ban, or a post/reply target that left `publish`. Every read
 * path (bell count, tab badges, list, paginated total, list_for_user) must
 * agree, and the rows come back when the ban lifts / the content returns.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;

class NotificationVisibilityTest extends WP_UnitTestCase {

	private int $recipient;
	private int $actor;
	private int $post_id;
	private int $reply_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->recipient = self::factory()->user->create();
		$this->actor     = self::factory()->user->create();

		$cat_id   = Category::create( array( 'name' => 'NV', 'slug' => 'nv-' . uniqid() ) );
		$space_id = Space::create(
			array(
				'title'       => 'NV Space',
				'slug'        => 'nv-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
			)
		);

		$this->post_id  = Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => $this->recipient,
				'title'     => 'NV Post',
				'slug'      => 'nv-post-' . uniqid(),
				'content'   => '<p>Body</p>',
			)
		);
		$this->reply_id = Reply::create(
			array(
				'post_id'   => $this->post_id,
				'author_id' => $this->actor,
				'content'   => '<p>Reply</p>',
				'status'    => 'publish',
			)
		);
	}

	private function notify( string $type, int $actor, string $object_type, int $object_id ): void {
		Notification::create(
			array(
				'user_id'     => $this->recipient,
				'actor_id'    => $actor,
				'type'        => $type,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'message'     => $type,
			)
		);
	}

	/**
	 * Every read path, freshly computed.
	 *
	 * @return array<string,int>
	 */
	private function visible(): array {
		Notification::bust_user_cache( $this->recipient );
		return array(
			'unread' => Notification::unread_count( $this->recipient ),
			'total'  => Notification::count_for_user( $this->recipient ),
			'list'   => count( Notification::list_for_user_with_targets( $this->recipient, 50, 0 ) ),
			'plain'  => count( Notification::list_for_user( $this->recipient, 50, 0 ) ),
			'tabs'   => Notification::counts_by_filter( $this->recipient )['all'],
		);
	}

	private function assert_all( int $expected, string $message ): void {
		foreach ( $this->visible() as $path => $count ) {
			$this->assertSame( $expected, $count, "{$message} ({$path})" );
		}
	}

	public function test_banned_actor_is_hidden_until_unbanned(): void {
		$this->notify( 'reply_to_post', $this->actor, 'post', $this->post_id );
		$this->notify( 'badge_earned', 0, 'badge', 1 );
		$this->assert_all( 2, 'both visible before the ban' );

		$ban = Restriction::ban( $this->actor, 'global_ban', 1 );
		$this->assertGreaterThan( 0, $ban );
		$this->assert_all( 1, 'banned actor row hidden on every path' );

		Restriction::remove_ban( $ban );
		$this->assert_all( 2, 'row returns once the ban lifts' );
	}

	public function test_expired_ban_does_not_hide(): void {
		$this->notify( 'reply_to_post', $this->actor, 'post', $this->post_id );
		Restriction::ban( $this->actor, 'global_ban', 1, null, null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->assert_all( 1, 'an expired ban hides nothing' );
	}

	public function test_space_ban_and_silence_do_not_hide(): void {
		$this->notify( 'reply_to_post', $this->actor, 'post', $this->post_id );
		Restriction::ban( $this->actor, 'silence', 1 );
		$this->assert_all( 1, 'only a site-wide ban hides the actor' );
	}

	public function test_non_published_targets_are_hidden(): void {
		$this->notify( 'vote_on_post', 1, 'post', $this->post_id );
		$this->notify( 'mention', 1, 'reply', $this->reply_id );
		$this->assert_all( 2, 'both targets published' );

		Reply::update( $this->reply_id, array( 'status' => 'spam' ) );
		$this->assert_all( 1, 'spammed reply target hidden' );

		Reply::update( $this->reply_id, array( 'status' => 'publish' ) );
		Post::update( $this->post_id, array( 'status' => 'trash' ) );
		$this->assert_all( 0, 'trashed post hides its own row and its replies' );

		Post::update( $this->post_id, array( 'status' => 'publish' ) );
		$this->assert_all( 2, 'restoring the content restores the rows' );
	}

	public function test_moderation_notices_survive_their_target_leaving_publish(): void {
		$this->notify( 'moderation', 1, 'post', $this->post_id );
		$this->notify( 'flag_resolved', 1, 'reply', $this->reply_id );
		Post::update( $this->post_id, array( 'status' => 'trash' ) );
		$this->assert_all( 2, '"your post was removed" must still reach its author' );
	}
}
