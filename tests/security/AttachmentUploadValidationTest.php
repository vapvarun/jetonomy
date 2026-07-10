<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\API\Media_Controller;

/** @covers \Jetonomy\API\Media_Controller::validate_upload */
class AttachmentUploadValidationTest extends WP_UnitTestCase {

	private function tmp( string $bytes, string $name ): array {
		$path = tempnam( sys_get_temp_dir(), 'jtup' );
		file_put_contents( $path, $bytes );
		return array( 'name' => $name, 'tmp_name' => $path, 'size' => strlen( $bytes ), 'error' => 0 );
	}

	public function test_png_within_limits_passes(): void {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		$this->assertTrue( Media_Controller::validate_upload( $this->tmp( $png, 'a.png' ) ) );
	}

	public function test_svg_is_rejected(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$res = Media_Controller::validate_upload( $this->tmp( $svg, 'x.svg' ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'jetonomy_upload_type', $res->get_error_code() );
	}

	public function test_extension_mime_mismatch_is_rejected(): void {
		// PHP payload renamed .png — extension says png, sniff says text/plain.
		$res = Media_Controller::validate_upload( $this->tmp( '<?php echo 1;', 'evil.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
	}

	public function test_oversize_is_rejected(): void {
		add_filter( 'jetonomy_upload_max_size', static fn() => 8 );
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		$res = Media_Controller::validate_upload( $this->tmp( $png, 'big.png' ) );
		remove_all_filters( 'jetonomy_upload_max_size' );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'jetonomy_upload_size', $res->get_error_code() );
	}
}
