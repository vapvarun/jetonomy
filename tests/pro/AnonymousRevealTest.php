<?php
/**
 * Unit tests for the Anonymous Posting Pro extension's Reveal.
 *
 * Verifies the site-admin-only, explicit-context reveal: normal admin browsing
 * still shows "Anonymous" (jetonomy_author_can_reveal only grants access
 * inside Reveal::reveal()'s explicit context), an explicit reveal by a
 * manage_options user resolves the real author AND writes one ActivityLog row,
 * and a non-admin's reveal attempt is denied outright.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Author;
use Jetonomy_Pro\Extensions\Anonymous_Posting\Reveal;

class AnonymousRevealTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — anonymous reveal tests skipped.' );
		}

		Schema::create_tables();
		( new Reveal() )->boot();
	}

	private function make_anon_post( int $author ): int {
		$cat   = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		return Post::create( array( 'space_id' => $space, 'author_id' => $author, 'title' => 'T', 'content' => 'c', 'is_anonymous' => 1 ) );
	}

	public function test_admin_browsing_still_sees_anonymous(): void {
		$author = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post = Post::find( $this->make_anon_post( $author ) );
		// No explicit reveal context → masked even for an admin.
		$this->assertSame( 'Anonymous', Author::for_display( $author, $post )['name'] );
	}

	public function test_explicit_reveal_returns_real_author_and_logs(): void {
		global $wpdb;
		$author = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$pid = $this->make_anon_post( $author );

		$result = ( new Reveal() )->reveal( 'post', $pid );

		$this->assertSame( $author, $result['id'] );
		$this->assertSame( 'Jane Doe', $result['name'] );

		$logged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_activity_log WHERE action = %s AND object_id = %d",
				'anonymous_author_revealed',
				$pid
			)
		);
		$this->assertSame( 1, $logged );
	}

	public function test_non_admin_reveal_is_denied(): void {
		$author = self::factory()->user->create();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member );
		$pid    = $this->make_anon_post( $author );
		$result = ( new Reveal() )->reveal( 'post', $pid );
		$this->assertArrayHasKey( 'error', $result );
	}
}
