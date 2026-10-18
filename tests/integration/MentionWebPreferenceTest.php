<?php
/**
 * A member who turned mention notifications off on the web gets no in-app
 * mention row and no push mirror, the same rule every other notification
 * type follows through Notifier::create_and_maybe_email().
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Mentions;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\UserProfile;

class MentionWebPreferenceTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->space = Space::create(
			array(
				'title'       => 'Mention prefs',
				'slug'        => 'mp-' . uniqid(),
				'category_id' => Category::create( array( 'name' => 'M', 'slug' => 'm-' . uniqid() ) ),
				'visibility'  => 'public',
			)
		);
	}

	private function mention( string $login ): int {
		$author = self::factory()->user->create();
		$post   = (int) Post::create( array( 'space_id' => $this->space, 'author_id' => $author, 'title' => 'Hi', 'content' => "Hello @{$login}", 'status' => 'publish' ) );
		Mentions::notify_for( 'post', $post );
		return $post;
	}

	private function mention_rows( int $user_id, int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_notifications WHERE user_id = %d AND type = 'mention' AND object_id = %d", $user_id, $post_id ) );
	}

	public function test_mention_turned_off_on_the_web_writes_no_row_and_emits_nothing(): void {
		$uid = self::factory()->user->create( array( 'user_login' => 'mp_quiet', 'user_nicename' => 'mp_quiet' ) );
		UserProfile::find_or_create( $uid );
		UserProfile::update_profile( $uid, array( 'settings' => wp_json_encode( array( 'notifications' => array( 'mention' => array( 'web' => false ) ) ) ) ) );

		$emitted = 0;
		$count   = static function () use ( &$emitted ): void {
			++$emitted;
		};
		add_action( 'jetonomy_notification_created', $count );

		$post = $this->mention( 'mp_quiet' );

		$this->assertSame( 0, $this->mention_rows( $uid, $post ) );
		$this->assertSame( 0, $emitted );
	}

	/** @covers-existing A member with the default preference is still notified. */
	public function test_mention_with_default_preference_is_notified(): void {
		$uid  = self::factory()->user->create( array( 'user_login' => 'mp_loud', 'user_nicename' => 'mp_loud' ) );
		$post = $this->mention( 'mp_loud' );

		$this->assertSame( 1, $this->mention_rows( $uid, $post ) );
	}
}
