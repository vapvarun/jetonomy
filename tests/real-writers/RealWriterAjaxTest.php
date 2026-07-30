<?php
namespace Jetonomy\Tests\RealWriters;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;

/**
 * The REAL wp-admin AJAX writer (Basecamp 10138808747, wave 7): div soup
 * through wp_ajax_jetonomy_update_post - nonce, capability gate, the
 * handler's own kses, then the model barrier - asserting the DB row.
 */
class RealWriterAjaxTest extends WP_Ajax_UnitTestCase {

	private const SOUP       = '<div>first line<br>second line</div><div><br></div><div>next paragraph</div>';
	private const NORMALIZED = "<p>first line<br />second line</p>\n<p>next paragraph</p>";

	public function test_admin_ajax_update_post_persists_normalized(): void {
		Schema::create_tables();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		foreach ( \Jetonomy\Permissions\Capabilities::all() as $cap ) {
			get_user_by( 'id', $admin )->add_cap( $cap );
		}
		wp_set_current_user( $admin );

		$cat_id   = Category::create( array( 'name' => 'Ajax Cat', 'slug' => 'ajax-cat-' . uniqid() ) );
		$space_id = Space::create( array(
			'title'       => 'Ajax Space',
			'slug'        => 'ajax-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		) );
		$post_id  = Post::create( array(
			'space_id'  => $space_id,
			'author_id' => $admin,
			'title'     => 'Ajax target',
			'slug'      => 'ajax-target-' . uniqid(),
			'content'   => '<p>clean before</p>',
		) );

		// The handler is registered by the Admin bootstrap on admin requests;
		// the Ajax test case does not construct it, so wire it directly.
		new \Jetonomy\Admin\Ajax\Content_Handler();

		$_POST = array(
			'nonce'   => wp_create_nonce( 'jetonomy_admin' ),
			'post_id' => (string) $post_id,
			'content' => wp_slash( self::SOUP ),
		);

		try {
			$this->_handleAjax( 'jetonomy_update_post' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json_* dies; the row assert below is the test.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( (bool) ( $response['success'] ?? false ), 'AJAX update must succeed: ' . $this->_last_response );
		$this->assertSame( self::NORMALIZED, Post::find( $post_id )->content, 'wp-admin AJAX writer must persist normalized paragraphs' );
	}
}
