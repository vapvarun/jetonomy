<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\Media_Library;

/**
 * Provenance + GC-safety of community media, from the FREE side.
 *
 * The Pro suite proves the GC honours these signals; this proves the signals
 * themselves. The load-bearing rule: is_ours() (the only gate to deletion) is
 * true ONLY for a file we tagged at upload time, and NEVER for a file the
 * backfill merely recognised — otherwise enabling attachments mid-migration
 * would delete another forum plugin's files off disk.
 */
class MediaLibraryProvenanceTest extends WP_UnitTestCase {

	public function test_tag_upload_marks_a_file_as_ours(): void {
		$aid = self::factory()->attachment->create_object( array( 'post_mime_type' => 'image/png', 'file' => 'x.png' ) );
		$this->assertFalse( Media_Library::is_ours( $aid ), 'Untagged media is not ours.' );

		Media_Library::tag_upload( $aid );

		$this->assertTrue( Media_Library::is_ours( $aid ) );
		$this->assertSame( '1', (string) get_post_meta( $aid, Media_Library::META_FLAG, true ) );
		$this->assertSame( 'upload', (string) get_post_meta( $aid, Media_Library::META_ORIGIN, true ) );
	}

	public function test_tag_upload_records_the_originating_space(): void {
		$aid = self::factory()->attachment->create_object( array( 'post_mime_type' => 'image/png', 'file' => 'x.png' ) );
		Media_Library::tag_upload( $aid, 42 );
		$this->assertSame( 42, (int) get_post_meta( $aid, Media_Library::META_SPACE, true ) );
	}

	public function test_tag_upload_ignores_a_non_positive_id(): void {
		Media_Library::tag_upload( 0 );
		$this->assertFalse( Media_Library::is_ours( 0 ) ); // No warning, no phantom meta.
	}

	/**
	 * REGRESSION GUARD (data loss). A file the backfill flagged as a community upload
	 * (META_FLAG only) is NOT ours to delete: that flag is inferred from the author
	 * lacking upload_files, which is true of every subscriber-authored attachment on
	 * the site — including another forum plugin's. Only META_ORIGIN='upload' makes it
	 * ours, and the backfill never sets that.
	 */
	public function test_backfill_flag_alone_does_not_make_a_file_ours(): void {
		$aid = self::factory()->attachment->create_object( array( 'post_mime_type' => 'image/png', 'file' => 'x.png' ) );
		update_post_meta( $aid, Media_Library::META_FLAG, 1 );

		$this->assertFalse( Media_Library::is_ours( $aid ), 'The backfill flag must never be read as provenance.' );
	}

	public function test_maybe_backfill_tags_subscriber_authored_media_and_skips_privileged_media(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );

		// A member upload that only reached the library through Jetonomy's REST path
		// (its author cannot upload_files).
		$member_upload = self::factory()->attachment->create_object( array( 'post_author' => $subscriber, 'post_mime_type' => 'image/png', 'file' => 'm.png' ) );
		// The owner's own media (author CAN upload_files).
		$owner_media = self::factory()->attachment->create_object( array( 'post_author' => $editor, 'post_mime_type' => 'image/png', 'file' => 'o.png' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Media_Library::OPT_BACKFILLED );

		$ml = new Media_Library();
		$ml->maybe_backfill(); // classifies this batch
		$ml->maybe_backfill(); // empty batch → marks the one-shot complete

		$this->assertSame( '1', (string) get_post_meta( $member_upload, Media_Library::META_FLAG, true ), 'A subscriber-authored upload must be flagged as a community upload.' );
		$this->assertSame( '', (string) get_post_meta( $member_upload, Media_Library::META_CHECKED, true ) );

		$this->assertSame( '', (string) get_post_meta( $owner_media, Media_Library::META_FLAG, true ), 'The owner/editor\'s own media must never be tagged as a community upload.' );
		$this->assertSame( '1', (string) get_post_meta( $owner_media, Media_Library::META_CHECKED, true ), 'Privileged media gets a skip-marker so it is not re-examined.' );

		// Neither is ever "ours to delete" — the backfill sets no provenance.
		$this->assertFalse( Media_Library::is_ours( $member_upload ) );
		$this->assertFalse( Media_Library::is_ours( $owner_media ) );

		$this->assertSame( '1', (string) get_option( Media_Library::OPT_BACKFILLED ), 'The backfill is one-shot: it must record completion so it never runs again.' );
	}

	public function test_maybe_backfill_is_a_noop_once_complete(): void {
		update_option( Media_Library::OPT_BACKFILLED, 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$aid = self::factory()->attachment->create_object( array( 'post_author' => self::factory()->user->create( array( 'role' => 'subscriber' ) ), 'post_mime_type' => 'image/png', 'file' => 'n.png' ) );

		( new Media_Library() )->maybe_backfill();

		$this->assertSame( '', (string) get_post_meta( $aid, Media_Library::META_FLAG, true ), 'Once complete, the backfill must not touch newly added media.' );
	}
}
