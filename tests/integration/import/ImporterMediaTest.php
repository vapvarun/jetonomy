<?php
namespace Jetonomy\Tests\Integration\Import;

use WP_UnitTestCase;
use Jetonomy\Import\Importer;

/**
 * Concrete stand-in for the abstract base importer so the shared media helpers
 * (register_body_media, ensure_media_id, resolve_upload_path, get_errors) can be
 * exercised directly. Every real importer inherits these unchanged, and they are
 * where the migration's file-recovery + path-traversal defence lives.
 */
class _Importer_Media_Double extends Importer {
	public function get_source_name(): string {
		return 'double';
	}
	public function is_source_available(): bool {
		return true;
	}
	public function get_source_stats(): array {
		return array();
	}
	public function get_total_count(): int {
		return 0;
	}
	public function run( array $options = array() ): array {
		return $this->results();
	}
	public function run_batch( string $phase, int $offset, int $batch_size ): array {
		return array( 'phase' => $phase, 'offset' => $offset, 'done' => true, 'processed' => 0 );
	}

	// Public proxies onto the protected helpers under test.
	public function p_register_body_media( string $body, string $prefix ): int {
		return $this->register_body_media( $body, $prefix );
	}
	public function p_ensure_media_id( int $known, string $url, string $name = '' ): int {
		return $this->ensure_media_id( $known, $url, $name );
	}
	public function p_resolve_upload_path( string $url ): string {
		return $this->resolve_upload_path( $url );
	}
}

class ImporterMediaTest extends WP_UnitTestCase {

	private string $uploads_dir;
	private string $uploads_url;
	private array $written = array();

	public function set_up(): void {
		parent::set_up();
		$uploads           = wp_get_upload_dir();
		$this->uploads_dir = (string) $uploads['basedir'];
		$this->uploads_url = (string) $uploads['baseurl'];
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		$this->written = array();
		parent::tear_down();
	}

	/** Drop a real file into a source-forum folder under uploads/ and return [abs_path, public_url]. */
	private function seed_upload( string $relative, ?string $copy_from = null ): array {
		$path = $this->uploads_dir . '/' . ltrim( $relative, '/' );
		wp_mkdir_p( dirname( $path ) );
		if ( $copy_from ) {
			copy( $copy_from, $path );
		} else {
			file_put_contents( $path, 'x' );
		}
		$this->written[] = $path;
		return array( $path, $this->uploads_url . '/' . ltrim( $relative, '/' ) );
	}

	private function importer(): _Importer_Media_Double {
		return new _Importer_Media_Double();
	}

	// ── resolve_upload_path ─────────────────────────────────────────────────

	public function test_resolve_upload_path_resolves_a_file_inside_uploads(): void {
		list( $path, $url ) = $this->seed_upload( 'wpforo/2024/pic.png', DIR_TESTDATA . '/images/test-image.png' );
		$this->assertSame( realpath( $path ), $this->importer()->p_resolve_upload_path( $url ) );
	}

	public function test_resolve_upload_path_rejects_directory_traversal(): void {
		// A body an old member could have typed years ago: escape uploads/ to reach
		// wp-config.php. realpath() containment must refuse it, or registering it would
		// hand out arbitrary file registration (and later deletion).
		$evil = $this->uploads_url . '/wpforo/../../../wp-config.php';
		$this->assertSame( '', $this->importer()->p_resolve_upload_path( $evil ) );
	}

	public function test_resolve_upload_path_ignores_offsite_urls(): void {
		$this->assertSame( '', $this->importer()->p_resolve_upload_path( 'https://cdn.example.com/wpforo/pic.png' ) );
		$this->assertSame( '', $this->importer()->p_resolve_upload_path( '' ) );
	}

	public function test_resolve_upload_path_returns_empty_for_a_missing_file(): void {
		// Under the uploads prefix, correct shape, but nothing on disk.
		$url = $this->uploads_url . '/wpforo/2024/does-not-exist.png';
		$this->assertSame( '', $this->importer()->p_resolve_upload_path( $url ) );
	}

	// ── ensure_media_id ─────────────────────────────────────────────────────

	public function test_ensure_media_id_reuses_a_known_media_id_without_reimporting(): void {
		$existing = self::factory()->attachment->create_object( array( 'post_mime_type' => 'image/png', 'file' => 'x.png' ) );
		$out      = $this->importer()->p_ensure_media_id( $existing, 'https://whatever/x.png' );
		$this->assertSame( $existing, $out, 'A media id the source already recorded must be reused, never re-imported.' );
	}

	public function test_ensure_media_id_registers_a_recovered_file(): void {
		list( , $url ) = $this->seed_upload( 'wpforo/2024/recovered.png', DIR_TESTDATA . '/images/test-image.png' );
		$id = $this->importer()->p_ensure_media_id( 0, $url, 'recovered.png' );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
	}

	public function test_ensure_media_id_refuses_a_disallowed_type_instead_of_octet_stream_fallback(): void {
		// The old code fell back to application/octet-stream, which turned "WP rejects
		// this file type" into "register it anyway" — the crack a planted file slipped
		// through. It must now log an error and return 0.
		list( , $url ) = $this->seed_upload( 'wpforo/2024/payload.xyz' );
		$importer      = $this->importer();

		$this->assertSame( 0, $importer->p_ensure_media_id( 0, $url ) );
		$errors = $importer->get_errors();
		$this->assertNotEmpty( $errors, 'A disallowed file type must be surfaced as an error, not swallowed.' );
		$this->assertSame( 'attachment', $errors[0]['type'] );
		$this->assertStringContainsString( 'Disallowed', $errors[0]['message'] );
	}

	public function test_ensure_media_id_returns_zero_for_a_missing_file(): void {
		$url = $this->uploads_url . '/wpforo/2024/gone.png';
		$this->assertSame( 0, $this->importer()->p_ensure_media_id( 0, $url ) );
	}

	// ── register_body_media ─────────────────────────────────────────────────

	public function test_register_body_media_registers_files_under_the_source_folder(): void {
		list( , $url ) = $this->seed_upload( 'wpforo/2024/inline.png', DIR_TESTDATA . '/images/test-image.png' );
		$body          = '<p>hi</p><img src="' . esc_url( $url ) . '" />';

		$n = $this->importer()->p_register_body_media( $body, 'wpforo' );
		$this->assertSame( 1, $n );

		// The inline image is now a media-library item pointing at the on-disk file.
		$ids   = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1 ) );
		$match = false;
		foreach ( $ids as $id ) {
			if ( str_ends_with( (string) get_attached_file( $id ), 'wpforo/2024/inline.png' ) ) {
				$match = true;
				break;
			}
		}
		$this->assertTrue( $match, 'The inline image must now be a media-library item pointing at the on-disk file.' );
	}

	public function test_register_body_media_does_not_rewrite_the_body(): void {
		// Not touching content is a feature: the method returns a count only, the body
		// it was handed is never mutated (it is passed by value, but we assert the
		// contract explicitly).
		list( , $url ) = $this->seed_upload( 'wpforo/2024/keep.png', DIR_TESTDATA . '/images/test-image.png' );
		$body          = '<img src="' . esc_url( $url ) . '" />';
		$before        = $body;

		$this->importer()->p_register_body_media( $body, 'wpforo' );
		$this->assertSame( $before, $body, 'register_body_media must not rewrite the post body.' );
	}

	public function test_register_body_media_ignores_offsite_urls(): void {
		$body = '<img src="https://cdn.example.com/wpforo/2024/remote.png" />';
		$this->assertSame( 0, $this->importer()->p_register_body_media( $body, 'wpforo' ), 'Off-site URLs are not our files to register.' );
	}

	public function test_register_body_media_rejects_traversal_in_the_body(): void {
		$body = '<a href="' . $this->uploads_url . '/wpforo/../../../wp-config.php">x</a>';
		$this->assertSame( 0, $this->importer()->p_register_body_media( $body, 'wpforo' ), 'A traversal href must never be registered.' );
	}

	public function test_register_body_media_handles_protocol_relative_urls(): void {
		// wpForo writes attachment hrefs protocol-relative (//host/path). Matching on the
		// PATH must still find the file.
		list( , $url )  = $this->seed_upload( 'wpforo/2024/proto.png', DIR_TESTDATA . '/images/test-image.png' );
		$protocol_rel   = preg_replace( '#^https?:#i', '', $url ); // //host/wp-content/uploads/...
		$body           = '<img src="' . esc_url( $protocol_rel ) . '" />';

		$n = $this->importer()->p_register_body_media( $body, 'wpforo' );
		$this->assertSame( 1, $n, 'A protocol-relative //host URL must resolve to the on-disk file.' );
	}

	public function test_register_body_media_only_touches_the_named_folder(): void {
		// A trailing-slash-scoped prefix: `wpforo` must not swallow `wpforo-backup`.
		list( , $backup_url ) = $this->seed_upload( 'wpforo-backup/2024/other.png', DIR_TESTDATA . '/images/test-image.png' );
		$body                 = '<img src="' . esc_url( $backup_url ) . '" />';

		$this->assertSame( 0, $this->importer()->p_register_body_media( $body, 'wpforo' ) );
	}

	public function test_register_body_media_is_a_noop_when_body_lacks_the_prefix(): void {
		$this->assertSame( 0, $this->importer()->p_register_body_media( '<p>no media here</p>', 'wpforo' ) );
		$this->assertSame( 0, $this->importer()->p_register_body_media( '', 'wpforo' ) );
	}
}
