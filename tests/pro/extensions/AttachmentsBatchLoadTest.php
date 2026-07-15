<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Attachment;
use Jetonomy_Pro\Extensions\Attachments\Model;

/**
 * Rendering goes through FREE's list renderer from 1.7.1 on.
 *
 * These tests used to call Pro's `Renderer::strip_html()` and count queries against
 * `jt_pro_attachments`. Both are gone — the method was deleted when the duplicate
 * renderer was removed, and the table was renamed. Counting a table nobody queries
 * meant the N+1 assertion below passed with zero hits no matter what the code did:
 * the exact test that was supposed to catch a regression could no longer see one.
 *
 * They now drive the real path — the `jetonomy_after_post_content` /
 * `jetonomy_after_reply_content` filters that the templates apply — so a broken
 * renderer or a lost cache prime actually fails the suite.
 */
class AttachmentsBatchLoadTest extends WP_UnitTestCase {

	use AttachmentFixtures;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( \Jetonomy\Attachments::class ) ) {
			$this->markTestSkipped( 'Free attachments renderer not loaded.' );
		}

		Schema::create_tables();
		( new \Jetonomy\Attachments() )->register();
	}

	/** The attachment table, as the renderer actually queries it. */
	private function attach_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'jt_attachments';
	}

	private function render_post( int $post_id ): string {
		return (string) apply_filters( 'jetonomy_after_post_content', '', (object) array( 'id' => $post_id ) );
	}

	private function render_reply( int $reply_id ): string {
		return (string) apply_filters( 'jetonomy_after_reply_content', '', (object) array( 'id' => $reply_id ) );
	}

	public function test_strip_renders_a_card(): void {
		Model::link( 'post', 5150, self::img(), 0 );

		$html = $this->render_post( 5150 );

		$this->assertStringContainsString( 'jt-attach', $html );
	}

	public function test_nothing_renders_when_there_are_no_attachments(): void {
		// No empty wrapper markup leaking into every post/reply on the site.
		$this->assertSame( '', $this->render_post( 5151 ) );
	}

	public function test_prime_makes_reply_render_zero_attachment_queries(): void {
		global $wpdb;
		list( $post_id, $reply_ids ) = self::seed_replies_with_attachments( 2 );

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
			$this->markTestSkipped( 'SAVEQUERIES not enabled in this test run.' );
		}

		// Rendering the POST primes every reply on it in one query (this is what the
		// single-post template does before it renders the reply cards).
		$this->render_post( $post_id );

		$before_count = count( $wpdb->queries );
		foreach ( $reply_ids as $rid ) {
			$this->render_reply( (int) $rid );
		}

		$table = $this->attach_table();
		$hits  = 0;
		foreach ( array_slice( $wpdb->queries, $before_count ) as $q ) {
			if ( false !== stripos( $q[0], $table ) ) {
				++$hits;
			}
		}

		$this->assertSame( 0, $hits, 'Rendering primed replies must issue zero attachment-table queries.' );
	}

	public function test_priming_through_pro_model_is_visible_to_free(): void {
		// Pro's Model is an empty subclass of free's Attachment, so the static cache is
		// shared. If that ever splits, priming via one and reading via the other
		// silently re-queries per row.
		global $wpdb;
		list( $post_id, $reply_ids ) = self::seed_replies_with_attachments( 1 );

		Model::prime_for_post( $post_id );

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || ! is_array( $wpdb->queries ) ) {
			// Without query logging we can still assert the cache is shared at all.
			$this->assertNotEmpty( Attachment::get_for( 'reply', (int) $reply_ids[0] ) );
			return;
		}

		$before = count( $wpdb->queries );
		Attachment::get_for( 'reply', (int) $reply_ids[0] );

		$this->assertSame( $before, count( $wpdb->queries ), 'Free re-queried a reply that Pro had already primed.' );
	}

	public function test_cards_render_without_any_enqueued_script(): void {
		Model::link( 'post', 2020, self::img(), 0 );

		$this->assertStringNotContainsString( '<script', $this->render_post( 2020 ) );
	}
}
