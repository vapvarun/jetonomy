<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\DB\Migrations\Migration_1_7_1;
use Jetonomy\Media_Library;
use Jetonomy\Models\Attachment;
use Jetonomy_Pro\Extensions\Attachments\Extension;
use Jetonomy_Pro\Extensions\Attachments\Model;

class AttachmentsModelTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Extension::class ) ) {
			$this->markTestSkipped( 'Pro attachments extension not loaded.' );
		}

		// The table is FREE's from 1.7.1, and Pro's activate() no longer creates it.
		Schema::create_tables();
		( new Extension() )->activate();
	}

	public function test_table_created(): void {
		global $wpdb;
		// jt_attachments, not jt_pro_attachments — the table moved to free in 1.7.1
		// (renamed; same table). This fails loudly if the model is ever pointed back
		// at a table free cannot read.
		$table = $wpdb->prefix . 'jt_attachments';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_pro_model_and_free_model_share_one_store(): void {
		// Pro's Model is an empty subclass of free's Attachment. If anyone redeclares
		// $cache or table() on it, the two split into separate static stores and
		// priming silently stops working — an N+1 nobody notices.
		Model::link( 'post', 7777, 4242, 0 );

		$this->assertSame( 1, Attachment::count_for( 'post', 7777 ) );
		$this->assertSame( 1, Model::count_for( 'post', 7777 ) );
	}

	public function test_meta_shape(): void {
		$meta = ( new Extension() )->meta();
		$this->assertSame( 'attachments', $meta['id'] );
		$this->assertSame( 'starter', $meta['requires'] );
	}

	public function test_link_count_and_get(): void {
		$id1 = Model::link( 'post', 4242, 900, 0 );
		$id2 = Model::link( 'post', 4242, 901, 1 );
		$this->assertGreaterThan( 0, $id1 );
		$this->assertSame( 2, Model::count_for( 'post', 4242 ) );
		$rows = Model::get_for( 'post', 4242 );
		$this->assertSame( array( 900, 901 ), array_map( static fn( $r ) => (int) $r->attachment_id, $rows ) );
		$this->assertTrue( Model::unlink( $id2 ) );
		$this->assertSame( 1, Model::count_for( 'post', 4242 ) );
	}

	public function test_link_is_idempotent(): void {
		// A resumed or re-run forum import must never double-attach a customer's file.
		$a = Model::link( 'post', 4343, 910, 0 );
		$b = Model::link( 'post', 4343, 910, 0 );

		$this->assertSame( $a, $b );
		$this->assertSame( 1, Model::count_for( 'post', 4343 ) );
	}

	public function test_delete_post_cascades_detach(): void {
		Model::link( 'post', 3131, 88, 0 );
		( new Extension() )->on_delete_post( 3131 );
		$this->assertSame( 0, Model::count_for( 'post', 3131 ) );
	}

	public function test_delete_reply_cascades_detach(): void {
		Model::link( 'reply', 3232, 89, 0 );
		( new Extension() )->on_delete_reply( 3232 );
		$this->assertSame( 0, Model::count_for( 'reply', 3232 ) );
	}

	public function test_gc_removes_orphan_link_rows(): void {
		Model::link( 'post', 5151, 999999999, 0 ); // attachment id that does not exist.
		( new Extension() )->gc();
		$this->assertSame( 0, Model::count_for( 'post', 5151 ) );
	}

	/**
	 * REGRESSION GUARD (data loss).
	 *
	 * The GC used to key on `_jetonomy_media`, which free's backfill also applies to
	 * media we did NOT create: it infers "community upload" from the author lacking
	 * upload_files, and that is true of every subscriber-authored attachment on the
	 * site — including another forum plugin's. wpForo authors its media as the posting
	 * member. So the GC force-deleted wpForo's own files, off disk, 24h after a
	 * customer enabled the extension: the very files a migration exists to rescue.
	 */
	public function test_gc_never_deletes_media_we_did_not_upload(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$foreign = self::factory()->post->create(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'post_author'   => $subscriber,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ),
			)
		);

		// Exactly what free's backfill does to any subscriber-authored attachment.
		update_post_meta( $foreign, Media_Library::META_FLAG, 1 );
		$this->assertFalse( Media_Library::is_ours( $foreign ) );

		( new Extension() )->gc();

		$this->assertNotNull( get_post( $foreign ), 'GC destroyed a file another plugin owns.' );
	}

	/**
	 * The other half: the GC must STILL reclaim our own abandoned uploads, or we have
	 * merely switched it off and junk accumulates forever.
	 */
	public function test_gc_still_reclaims_our_own_abandoned_upload(): void {
		$ours = self::factory()->post->create(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ),
			)
		);

		Media_Library::tag_upload( $ours ); // records provenance at upload time
		$this->assertTrue( Media_Library::is_ours( $ours ) );

		( new Extension() )->gc();

		$this->assertNull( get_post( $ours ), 'GC no longer reclaims abandoned uploads.' );
	}

	/**
	 * REGRESSION GUARD (data loss).
	 *
	 * jt_pro_attachments SHIPPED in Pro 1.7.0, so live sites hold real rows in it and
	 * 1.7.1 renames it. If Pro is reactivated before free's migrator runs, an EMPTY
	 * jt_attachments already exists — and an earlier cut of the migration then saw the
	 * table, skipped the move, and orphaned every row (3 in, 0 out). It must MERGE.
	 */
	public function test_migration_merges_when_both_tables_exist(): void {
		global $wpdb;

		$old = $wpdb->prefix . 'jt_pro_attachments';
		$new = $wpdb->prefix . 'jt_attachments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$old}" );
		$wpdb->query( "CREATE TABLE {$old} LIKE {$new}" );
		$wpdb->query( "INSERT INTO {$old} (object_type, object_id, attachment_id, sort, created_at) VALUES ('post', 8181, 501, 0, NOW()), ('post', 8181, 502, 1, NOW())" );

		( new Migration_1_7_1() )->up();

		$this->assertSame( 2, Model::count_for( 'post', 8181 ), 'Migration lost the customer rows.' );
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ),
			'Old table left behind after a complete merge.'
		);
		// phpcs:enable
	}

	public function test_gc_schedule_and_clear(): void {
		$ext = new Extension();
		$ext->activate();
		$scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( Extension::GC_HOOK, array(), 'jetonomy' )
			: (bool) wp_next_scheduled( Extension::GC_HOOK );
		$this->assertTrue( (bool) $scheduled );

		$ext->deactivate();
		$still_scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( Extension::GC_HOOK, array(), 'jetonomy' )
			: (bool) wp_next_scheduled( Extension::GC_HOOK );
		$this->assertFalse( (bool) $still_scheduled );
	}
}
