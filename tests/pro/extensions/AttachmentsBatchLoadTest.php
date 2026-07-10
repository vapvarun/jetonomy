<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Attachments\Model;
use Jetonomy_Pro\Extensions\Attachments\Renderer;

class AttachmentsBatchLoadTest extends WP_UnitTestCase {

	use AttachmentFixtures;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Renderer::class ) ) {
			$this->markTestSkipped( 'Pro attachments extension not loaded.' );
		}
	}

	public function test_strip_renders_pdf_button_and_image(): void {
		Model::link( 'post', 5150, self::img(), 0 );
		$renderer = new Renderer();
		$html     = $renderer->strip_html( 'post', 5150 );
		$this->assertStringContainsString( 'jt-attach', $html );
	}

	public function test_prime_makes_reply_render_zero_attachment_queries(): void {
		global $wpdb;
		list( $post_id, $reply_ids ) = self::seed_replies_with_attachments( 2 );

		Model::prime_for_post( $post_id );

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
			$this->markTestSkipped( 'SAVEQUERIES not enabled in this test run.' );
		}
		$before_count = count( $wpdb->queries );
		$renderer     = new Renderer();
		foreach ( $reply_ids as $rid ) {
			$renderer->strip_html( 'reply', $rid );
		}
		$attach_table = $wpdb->prefix . 'jt_pro_attachments';
		$hits         = 0;
		foreach ( array_slice( $wpdb->queries, $before_count ) as $q ) {
			if ( false !== stripos( $q[0], $attach_table ) ) {
				++$hits;
			}
		}
		$this->assertSame( 0, $hits, 'Rendering primed replies must issue zero attachment-table queries.' );
	}

	public function test_cards_render_without_any_enqueued_script(): void {
		Model::link( 'post', 2020, self::img(), 0 );
		$html = ( new Renderer() )->strip_html( 'post', 2020 );
		$this->assertStringNotContainsString( '<script', $html );
	}
}
