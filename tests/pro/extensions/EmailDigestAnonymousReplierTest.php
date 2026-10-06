<?php
/**
 * The email digest must never name an anonymous replier.
 *
 * An email cannot be recalled, so "Replies to Your Posts" mask an anonymous
 * reply the way the site does, whoever is the current user when it renders.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy_Pro\Extensions\Email_Digest\Extension;

class EmailDigestAnonymousReplierTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active - digest tests skipped.' );
		}
		Schema::create_tables();
	}

	public function test_an_anonymous_replier_is_masked_and_a_named_one_is_not(): void {
		$author = self::factory()->user->create( array( 'display_name' => 'Topic Author' ) );
		$anon   = self::factory()->user->create( array( 'display_name' => 'Secret Sam' ) );
		$named  = self::factory()->user->create( array( 'display_name' => 'Named Nora' ) );
		$cat    = Category::create( array( 'name' => 'D', 'slug' => 'd-' . uniqid() ) );
		$space  = Space::create( array( 'title' => 'D', 'slug' => 'd-s-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public' ) );
		$post   = Post::create( array( 'space_id' => $space, 'author_id' => $author, 'title' => 'Digest topic', 'slug' => 'd-p-' . uniqid(), 'content' => '<p>T</p>' ) );
		Reply::create( array( 'post_id' => $post, 'author_id' => $anon, 'content' => '<p>hidden hello</p>', 'content_plain' => 'hidden hello', 'is_anonymous' => 1 ) );
		Reply::create( array( 'post_id' => $post, 'author_id' => $named, 'content' => '<p>open hello</p>', 'content_plain' => 'open hello' ) );

		$ext = new Extension();
		$dig = $ext->compile_digest( $author, 'daily' );

		// Viewer 0 is the cron run; an administrator is the "send me a test" / preview run.
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'administrator' ) ) ) as $viewer ) {
			wp_set_current_user( $viewer );
			$html = $ext->render_digest_html( get_userdata( $author ), $dig, 'daily' );
			$this->assertStringNotContainsString( 'Secret Sam', $html, "anonymous replier named to viewer $viewer" );
			$this->assertStringContainsString( 'Anonymous', $html );
			$this->assertStringContainsString( 'Named Nora', $html, 'a named replier is still named' );
		}
		wp_set_current_user( 0 );
	}
}
