<?php
namespace Jetonomy\Tests\RealWriters;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\DB\Schema;

/**
 * ACTUAL-writer persistence coverage (Basecamp 10138808747, wave 7).
 *
 * The choke-point suite proves the model barrier; QA's standing blocker was
 * that no test drove div soup through the REAL writers end-to-end. These do:
 * the Abilities execute callback, the bbPress importer against genuine
 * source CPT rows, and Pro reply-by-email's REST inbound webhook - each
 * asserting the persisted DB row, not a return value. (The wp-admin AJAX
 * writer is exercised in RealWriterAjaxTest, which needs the Ajax test
 * case's die-handling.)
 */
class RealWriterPersistenceTest extends WP_UnitTestCase {

	private const SOUP       = '<div>first line<br>second line</div><div><br></div><div>next paragraph</div>';
	private const NORMALIZED = "<p>first line<br />second line</p>\n<p>next paragraph</p>";

	private int $space_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		foreach ( \Jetonomy\Permissions\Capabilities::all() as $cap ) {
			get_user_by( 'id', $this->author_id )->add_cap( $cap );
		}
		$cat_id         = Category::create( array( 'name' => 'RW Cat', 'slug' => 'rw-cat-' . uniqid() ) );
		$this->space_id = Space::create( array(
			'title'       => 'RW Space',
			'slug'        => 'rw-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
		) );
		SpaceMember::add( $this->space_id, $this->author_id, 'admin' );
		wp_set_current_user( $this->author_id );
	}

	public function test_abilities_execute_callback_persists_normalized(): void {
		// The REGISTERED Abilities execute callback, not a model-shaped stand-in.
		$abilities = new \Jetonomy\Abilities();
		$abilities->execute_create_post( array(
			'space_id' => $this->space_id,
			'title'    => 'Ability soup post',
			'content'  => self::SOUP,
		) );

		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}jt_posts WHERE title = 'Ability soup post'" );
		$this->assertNotNull( $row, 'ability must create the row' );
		$this->assertSame( self::NORMALIZED, $row->content, 'Abilities writer must persist normalized paragraphs' );
	}

	public function test_bbpress_importer_persists_normalized(): void {
		// GENUINE bbPress source rows: forum + topic CPT posts in wp_posts,
		// the exact shape the importer queries. The topic body is div soup,
		// as real bbPress content (TinyMCE-era) frequently is.
		$forum_id = self::factory()->post->create( array(
			'post_type'   => 'forum',
			'post_status' => 'publish',
			'post_title'  => 'Imported Forum',
		) );
		$topic_id = self::factory()->post->create( array(
			'post_type'    => 'topic',
			'post_status'  => 'publish',
			'post_parent'  => $forum_id,
			'post_author'  => $this->author_id,
			'post_title'   => 'Imported soup topic',
			'post_content' => self::SOUP,
		) );

		$importer = new \Jetonomy\Import\Bbpress_Importer();
		$this->assertTrue( $importer->is_source_available(), 'precondition: importer sees source rows' );
		$importer->run( array() );

		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}jt_posts WHERE title = 'Imported soup topic'" );
		$this->assertNotNull( $row, 'importer must create the jt_posts row' );
		$this->assertSame( self::NORMALIZED, $row->content, 'imported content must persist normalized' );
	}

	public function test_pro_reply_by_email_inbound_persists_normalized(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Extensions\Reply_By_Email\Extension' ) ) {
			$this->markTestSkipped( 'Pro not loaded (free-only run).' );
		}

		$post_id = Post::create( array(
			'space_id'  => $this->space_id,
			'author_id' => $this->author_id,
			'title'     => 'Email reply target',
			'slug'      => 'email-reply-target-' . uniqid(),
			'content'   => '<p>parent</p>',
		) );

		update_option(
			'jetonomy_pro_reply_by_email',
			array(
				'enabled'        => true,
				'method'         => 'webhook',
				'webhook_secret' => '',
			)
		);

		$ext   = new \Jetonomy_Pro\Extensions\Reply_By_Email\Extension();
		$token = \Jetonomy_Pro\Extensions\Reply_By_Email\Extension::generate_token( $this->author_id, $post_id );

		// The REAL inbound REST writer, fed the Mailgun payload shape with
		// pasted-HTML soup in the text body (QA: the journey fixture only
		// ever sent plain text).
		// Inbound bodies are PLAIN TEXT (the webhook sanitizes markup away);
		// paragraph structure arrives as blank-line separation.
		$request = new WP_REST_Request( 'POST', '/jetonomy-pro/v1/inbound-email' );
		$request->set_param( 'To', 'reply+' . $token . '@example.com' );
		$request->set_param( 'body-plain', "first line\nsecond line\n\nnext paragraph" );
		$response = $ext->rest_inbound( $request );

		$this->assertFalse( is_wp_error( $response ), is_wp_error( $response ) ? $response->get_error_message() : '' );

		global $wpdb;
		$reply = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_replies WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id )
		);
		$this->assertNotNull( $reply, 'inbound email must create the reply row' );
		$this->assertStringNotContainsString( '<div>', (string) $reply->content, 'no div soup may persist via email' );
		$this->assertStringContainsString( '<p>', (string) $reply->content, 'email reply persists as paragraphs' );
		$this->assertStringContainsString( 'next paragraph', (string) $reply->content );
	}
}
