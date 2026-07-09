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

	public function test_settings_sanitize_drops_svg_and_clamps(): void {
		$out = \Jetonomy_Pro\Extensions\Attachments\Settings::sanitize(
			array(
				'allowed_types'  => array( 'png', 'svg', 'exe', 'pdf' ),
				'max_size_bytes' => 999999999999,
				'max_files'      => 99,
			)
		);
		$this->assertSame( array( 'png', 'pdf' ), array_values( $out['allowed_types'] ) );
		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $out['max_size_bytes'] );
		$this->assertSame( 20, $out['max_files'] );
	}

	public function test_attach_enforces_max_files(): void {
		$this->assertTrue( method_exists( \Jetonomy_Pro\Extensions\Attachments\Rest::class, 'attach' ) );
	}

	public function test_post_payload_gets_attachments_array(): void {
		\Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 7777, 42, 0 );
		$post = (object) array( 'id' => 7777 );
		$rest = new \Jetonomy_Pro\Extensions\Attachments\Rest( new \Jetonomy_Pro\Extensions\Attachments\Extension() );
		$data = $rest->inject_post_payload( array( 'id' => 7777 ), $post );
		$this->assertArrayHasKey( 'attachments', $data );
		$this->assertSame( 42, (int) $data['attachments'][0]['id'] );
	}

	public function test_non_owner_cannot_attach(): void {
		$owner = self::factory()->user->create();
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$aid   = self::factory()->attachment->create_object( array( 'post_author' => $owner ), 0, array( 'post_mime_type' => 'image/png' ) );
		wp_set_current_user( $other );
		$req = new \WP_REST_Request( 'POST', '/jetonomy-pro/v1/attachments' );
		$req->set_param( 'object_type', 'post' );
		$req->set_param( 'object_id', 5555 );
		$req->set_param( 'attachment_id', $aid );
		$req->set_param( 'sort', 0 );
		$res = ( new \Jetonomy_Pro\Extensions\Attachments\Rest( new \Jetonomy_Pro\Extensions\Attachments\Extension() ) )->attach( $req );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}

	public function test_owner_can_detach_own_attachment(): void {
		$owner = self::factory()->user->create();
		$aid   = self::factory()->attachment->create_object( array( 'post_author' => $owner ), 0, array( 'post_mime_type' => 'image/png' ) );
		$link  = \Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 6666, $aid, 0 );
		wp_set_current_user( $owner );
		$req = new \WP_REST_Request( 'DELETE' );
		$req->set_url_params( array( 'id' => $link ) );
		$res = ( new \Jetonomy_Pro\Extensions\Attachments\Rest( new \Jetonomy_Pro\Extensions\Attachments\Extension() ) )->detach( $req );
		$this->assertTrue( $res->get_data()['deleted'] );
		$this->assertSame( 0, \Jetonomy_Pro\Extensions\Attachments\Model::count_for( 'post', 6666 ) );
	}

	public function test_link_on_create_post_reads_attachment_ids_from_request(): void {
		$owner = self::factory()->user->create();
		$a1    = self::factory()->attachment->create_object( array( 'post_author' => $owner ), 0, array( 'post_mime_type' => 'image/png' ) );
		$a2    = self::factory()->attachment->create_object( array( 'post_author' => $owner ), 0, array( 'post_mime_type' => 'image/png' ) );
		wp_set_current_user( $owner );

		$req = new \WP_REST_Request( 'POST', '/jetonomy/v1/posts' );
		$req->set_param( 'attachment_ids', $a1 . ',' . $a2 );

		( new \Jetonomy_Pro\Extensions\Attachments\Extension() )->link_on_create_post( 9191, 1, $req );
		$this->assertSame( 2, \Jetonomy_Pro\Extensions\Attachments\Model::count_for( 'post', 9191 ) );
	}

	public function test_link_on_create_post_ignores_ids_owned_by_someone_else(): void {
		$owner   = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$foreign = self::factory()->attachment->create_object( array( 'post_author' => $other ), 0, array( 'post_mime_type' => 'image/png' ) );
		wp_set_current_user( $owner );

		$req = new \WP_REST_Request( 'POST', '/jetonomy/v1/posts' );
		$req->set_param( 'attachment_ids', (string) $foreign );

		( new \Jetonomy_Pro\Extensions\Attachments\Extension() )->link_on_create_post( 9292, 1, $req );
		$this->assertSame( 0, \Jetonomy_Pro\Extensions\Attachments\Model::count_for( 'post', 9292 ) );
	}

	public function test_render_composer_attach_button_has_data_attribute(): void {
		ob_start();
		( new \Jetonomy_Pro\Extensions\Attachments\Extension() )->render_composer_attach_button( 1, 0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-jt-attach="1"', $html );
	}

	/**
	 * Guards the lazy-load contract (spec §4): pdf.js may only ever be reached
	 * via a dynamic import() inside pdf-viewer.js, triggered from a PDF-card
	 * click — never a static import in the always-loaded frontend module.
	 */
	public function test_pdfjs_is_not_in_the_initial_frontend_bundle(): void {
		$frontend = file_get_contents( JETONOMY_PRO_DIR . 'assets/js/attachments-frontend.js' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*import\s+[^;]*pdfjs/m', $frontend, 'pdf.js must not be statically imported in the frontend module.' );
		$this->assertMatchesRegularExpression( "/import\\(\\s*['\"]\\.\\/pdf-viewer/", $frontend, 'PDF viewer must be dynamically imported on click.' );
	}

	public function test_pdfjs_vendored_lib_files_exist(): void {
		$this->assertFileExists( JETONOMY_PRO_DIR . 'assets/lib/pdfjs/pdf.min.mjs' );
		$this->assertFileExists( JETONOMY_PRO_DIR . 'assets/lib/pdfjs/pdf.worker.min.mjs' );
	}

	public function test_pdf_viewer_only_dynamically_imports_pdfjs(): void {
		$viewer = file_get_contents( JETONOMY_PRO_DIR . 'assets/js/pdf-viewer.js' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*import\s+[^;]*pdfjs/m', $viewer );
		$this->assertMatchesRegularExpression( "/import\\(\\s*['\"]\\.\\.\\/lib\\/pdfjs\\/pdf\\.min\\.mjs/", $viewer );
	}
}
