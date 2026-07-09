<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Attachments\Uploader;

class AttachmentsRestTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Uploader::class ) ) {
			$this->markTestSkipped( 'Pro attachments extension not loaded.' );
		}
	}

	public function test_pdf_added_to_allow_list_when_enabled(): void {
		update_option(
			'jetonomy_pro_attachments',
			array(
				'allowed_types'  => array( 'png', 'pdf' ),
				'max_size_bytes' => 1234,
				'max_files'      => 5,
			)
		);
		$out = Uploader::extend_allowed_types( array( 'png' => 'image/png' ) );
		$this->assertContains( 'application/pdf', $out );
		$this->assertNotContains( 'image/svg+xml', $out ); // never svg
		$this->assertSame( 1234, Uploader::extend_max_size( 999 ) );
	}

	public function test_svg_never_added_even_if_configured(): void {
		update_option(
			'jetonomy_pro_attachments',
			array( 'allowed_types' => array( 'png', 'svg' ), 'max_size_bytes' => 999, 'max_files' => 5 )
		);
		$out = Uploader::extend_allowed_types( array( 'png' => 'image/png' ) );
		$this->assertNotContains( 'image/svg+xml', $out );
	}
}
