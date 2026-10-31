<?php
/**
 * Erasing a member anonymises their topics and replies (author_id -> 0, rows
 * kept); the files on that content must follow the same rule, so the media
 * item no longer points at the erased person. Media outside the forum is left
 * alone.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Attachment;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;

class PrivacyAttachmentEraseTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/** @return array{0:int,1:int,2:int,3:int} user, post, linked media, unlinked media */
	private function seed(): array {
		$user  = self::factory()->user->create( array( 'user_email' => 'erase-' . uniqid() . '@example.test' ) );
		$cat   = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$post  = Post::create( array( 'space_id' => $space, 'author_id' => $user, 'title' => 'T', 'content' => 'c' ) );
		$media = self::factory()->attachment->create( array( 'post_author' => $user ) );
		$other = self::factory()->attachment->create( array( 'post_author' => $user ) );
		Attachment::link( 'post', $post, $media );
		return array( $user, $post, $media, $other );
	}

	public function test_eraser_clears_uploader_on_forum_media_and_keeps_the_file(): void {
		list( $user, $post, $media, $other ) = $this->seed();

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$email   = get_userdata( $user )->user_email;
		foreach ( $erasers as $eraser ) {
			for ( $page = 1, $done = false; ! $done && $page < 20; $page++ ) {
				$done = (bool) call_user_func( $eraser['callback'], $email, $page )['done'];
			}
		}

		$this->assertSame( 0, (int) Post::find( $post )->author_id );
		$this->assertSame( 0, (int) get_post_field( 'post_author', $media ), 'Forum media still points at the erased member.' );
		$this->assertSame( 1, Attachment::count_links_to( $media ), 'The file stays on its topic.' );
		$this->assertSame( $user, (int) get_post_field( 'post_author', $other ), 'Media outside the forum is not ours to touch.' );
	}

	public function test_account_delete_keeps_forum_media_with_its_topic(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		list( $user, $post, $media ) = $this->seed();

		wp_delete_user( $user );

		$this->assertNotNull( get_post( $media ), 'Deleting the account deleted a file that its kept topic still shows.' );
		$this->assertSame( 0, (int) get_post_field( 'post_author', $media ) );
	}
}
