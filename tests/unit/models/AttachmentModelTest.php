<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Attachment;

/**
 * Direct coverage of FREE's Jetonomy\Models\Attachment.
 *
 * The Pro suite exercises this class through its empty subclass; these tests
 * drive the free model itself — the one a free-only site actually runs — so the
 * link store, batch primer, hydrate shape and REST payload are guarded without
 * Pro loaded. Every "issues one query" / "issues zero queries" assertion counts
 * only statements that touch the attachment table (SAVEQUERIES, enabled in the
 * bootstrap), the same technique the Pro batch-load test uses.
 */
class AttachmentModelTest extends WP_UnitTestCase {

	/** Fresh, collision-free object-id base per test so the shared static cache never bleeds across tests. */
	private int $oid;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->oid = 710000 + wp_rand( 1, 60000 );
	}

	private function attach_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jt_attachments';
	}

	/** Queries issued against the attachment table since $baseline. */
	private function attach_queries_since( int $baseline ): int {
		global $wpdb;
		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || ! is_array( $wpdb->queries ) ) {
			return -1; // Signals "cannot measure" to the caller.
		}
		$table = $this->attach_table();
		$hits  = 0;
		foreach ( array_slice( $wpdb->queries, $baseline ) as $q ) {
			if ( false !== stripos( $q[0], $table ) ) {
				++$hits;
			}
		}
		return $hits;
	}

	/** A real uploaded image attachment (has a file on disk + generated sizes). */
	private function img(): int {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png' );
	}

	// ── link / get / count / unlink ─────────────────────────────────────────

	public function test_link_creates_row_and_returns_id(): void {
		$id = Attachment::link( 'post', $this->oid, 900, 0 );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, Attachment::count_for( 'post', $this->oid ) );
	}

	public function test_link_is_idempotent(): void {
		$a = Attachment::link( 'post', $this->oid, 910, 0 );
		$b = Attachment::link( 'post', $this->oid, 910, 0 );
		$this->assertSame( $a, $b, 'Re-linking the same file must return the same row, never a second row.' );
		$this->assertSame( 1, Attachment::count_for( 'post', $this->oid ) );
	}

	public function test_object_type_is_normalised_to_post_for_anything_but_reply(): void {
		// link() coerces any non-'reply' type to 'post'; a bogus type must not create a
		// silently unreadable row under a third bucket.
		Attachment::link( 'garbage', $this->oid, 920, 0 );
		$this->assertSame( 1, Attachment::count_for( 'post', $this->oid ) );
		$this->assertSame( 0, Attachment::count_for( 'garbage', $this->oid ) );
	}

	public function test_get_for_returns_rows_ordered_by_sort(): void {
		Attachment::link( 'post', $this->oid, 902, 5 );
		Attachment::link( 'post', $this->oid, 901, 1 );
		Attachment::link( 'post', $this->oid, 900, 0 );

		$rows = Attachment::get_for( 'post', $this->oid );
		$this->assertSame(
			array( 900, 901, 902 ),
			array_map( static fn( $r ) => (int) $r->attachment_id, $rows )
		);
	}

	public function test_get_for_caches_second_call_issues_zero_queries(): void {
		Attachment::link( 'post', $this->oid, 900, 0 );
		Attachment::get_for( 'post', $this->oid ); // primes the per-object cache

		global $wpdb;
		$baseline = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		Attachment::get_for( 'post', $this->oid );

		$hits = $this->attach_queries_since( $baseline );
		if ( -1 === $hits ) {
			$this->markTestSkipped( 'SAVEQUERIES not enabled in this test run.' );
		}
		$this->assertSame( 0, $hits, 'A cached get_for() must not re-query the attachment table.' );
	}

	public function test_unlink_removes_row_and_invalidates_cache(): void {
		$id = Attachment::link( 'post', $this->oid, 900, 0 );
		Attachment::get_for( 'post', $this->oid ); // seed cache

		$this->assertTrue( Attachment::unlink( $id ) );
		// Reads the fresh state, not the stale cache.
		$this->assertSame( 0, Attachment::count_for( 'post', $this->oid ) );
		$this->assertSame( array(), Attachment::get_for( 'post', $this->oid ) );
	}

	public function test_unlink_all_detaches_everything_for_the_object(): void {
		Attachment::link( 'post', $this->oid, 900, 0 );
		Attachment::link( 'post', $this->oid, 901, 1 );
		Attachment::link( 'post', $this->oid, 902, 2 );

		$n = Attachment::unlink_all( 'post', $this->oid );
		$this->assertSame( 3, $n );
		$this->assertSame( 0, Attachment::count_for( 'post', $this->oid ) );
	}

	public function test_find_and_owner_of(): void {
		$owner = self::factory()->user->create();
		$aid   = self::factory()->attachment->create_object( array( 'post_author' => $owner ), 0, array( 'post_mime_type' => 'image/png' ) );
		$id    = Attachment::link( 'post', $this->oid, $aid, 0 );

		$row = Attachment::find( $id );
		$this->assertNotNull( $row );
		$this->assertSame( $aid, (int) $row->attachment_id );
		$this->assertSame( $owner, Attachment::owner_of( $id ) );
		$this->assertSame( 0, Attachment::owner_of( 99999999 ), 'owner_of() on a missing link must be 0, not a warning.' );
	}

	// ── get_for_many: one query for N objects ───────────────────────────────

	public function test_get_for_many_returns_a_group_per_object_including_empties(): void {
		$a = $this->oid;
		$b = $this->oid + 1;
		$c = $this->oid + 2; // no attachments
		Attachment::link( 'post', $a, 900, 0 );
		Attachment::link( 'post', $b, 901, 0 );
		Attachment::link( 'post', $b, 902, 1 );

		$grouped = Attachment::get_for_many( 'post', array( $a, $b, $c ) );

		$this->assertCount( 1, $grouped[ $a ] );
		$this->assertCount( 2, $grouped[ $b ] );
		$this->assertSame( array(), $grouped[ $c ], 'An object with no attachments must map to an empty array, not be absent.' );
	}

	public function test_get_for_many_issues_exactly_one_query_for_n_objects(): void {
		$ids = array( $this->oid, $this->oid + 1, $this->oid + 2, $this->oid + 3 );
		foreach ( $ids as $i => $oid ) {
			Attachment::link( 'post', $oid, 900 + $i, 0 );
		}

		global $wpdb;
		$baseline = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		Attachment::get_for_many( 'post', $ids );

		$hits = $this->attach_queries_since( $baseline );
		if ( -1 === $hits ) {
			$this->markTestSkipped( 'SAVEQUERIES not enabled in this test run.' );
		}
		$this->assertSame( 1, $hits, 'get_for_many() must load N objects in a single query, not one per object.' );
	}

	public function test_get_for_many_seeds_cache_so_get_for_never_requeries(): void {
		$ids = array( $this->oid, $this->oid + 1, $this->oid + 2 );
		Attachment::link( 'post', $ids[0], 900, 0 );
		Attachment::get_for_many( 'post', $ids );

		global $wpdb;
		$baseline = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		Attachment::get_for( 'post', $ids[0] ); // has rows
		Attachment::get_for( 'post', $ids[1] ); // empty, but still primed

		$hits = $this->attach_queries_since( $baseline );
		if ( -1 === $hits ) {
			$this->markTestSkipped( 'SAVEQUERIES not enabled in this test run.' );
		}
		$this->assertSame( 0, $hits, 'get_for() after a batch prime must hit the cache, even for an empty object.' );
	}

	public function test_get_for_many_with_no_ids_is_a_noop(): void {
		$this->assertSame( array(), Attachment::get_for_many( 'post', array() ) );
		$this->assertSame( array(), Attachment::get_for_many( 'post', array( 0, 0 ) ) );
	}

	// ── prime_for_post: zero per-row queries ────────────────────────────────

	public function test_prime_for_post_makes_reply_reads_issue_zero_queries(): void {
		global $wpdb;
		$post_id = $this->oid;
		$reply_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'jt_replies',
				array(
					'post_id'    => $post_id,
					'author_id'  => 1,
					'content'    => 'x',
					'created_at' => current_time( 'mysql', true ),
				)
			);
			$rid         = (int) $wpdb->insert_id;
			$reply_ids[] = $rid;
			Attachment::link( 'reply', $rid, 900 + $i, 0 );
		}
		// One reply deliberately carries no attachment — priming must still seed it.
		$wpdb->insert(
			$wpdb->prefix . 'jt_replies',
			array( 'post_id' => $post_id, 'author_id' => 1, 'content' => 'x', 'created_at' => current_time( 'mysql', true ) )
		);
		$empty_reply = (int) $wpdb->insert_id;
		$reply_ids[] = $empty_reply;

		Attachment::prime_for_post( $post_id );

		$baseline = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		foreach ( $reply_ids as $rid ) {
			Attachment::get_for( 'reply', $rid );
		}

		$hits = $this->attach_queries_since( $baseline );
		if ( -1 === $hits ) {
			$this->markTestSkipped( 'SAVEQUERIES not enabled in this test run.' );
		}
		$this->assertSame( 0, $hits, 'After prime_for_post(), reading every reply (incl. the empty one) must issue zero attachment queries.' );
		$this->assertNotEmpty( Attachment::get_for( 'reply', $reply_ids[0] ) );
		$this->assertSame( array(), Attachment::get_for( 'reply', $empty_reply ) );
	}

	// ── hydrate ─────────────────────────────────────────────────────────────

	public function test_hydrate_returns_the_canonical_shape_for_a_real_image(): void {
		$aid  = $this->img();
		$id   = Attachment::link( 'post', $this->oid, $aid, 0 );
		$data = Attachment::hydrate( (object) array( 'id' => $id, 'attachment_id' => $aid ) );

		$this->assertIsArray( $data );
		foreach ( array( 'id', 'link_id', 'url', 'thumb', 'mime', 'name', 'size', 'type', 'ext', 'is_image' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "Canonical attachment shape missing '{$key}'." );
		}
		$this->assertSame( $aid, $data['id'], 'id is the MEDIA id (established contract).' );
		$this->assertSame( $id, $data['link_id'], 'link_id is the link-row id.' );
		$this->assertSame( 'image', $data['type'] );
		$this->assertTrue( $data['is_image'] );
		$this->assertSame( 'image/png', $data['mime'] );
		$this->assertSame( 'PNG', $data['ext'] );
		$this->assertGreaterThan( 0, $data['size'], 'A real file on disk must report a non-zero size.' );
		$this->assertStringContainsString( 'test-image', $data['name'] );
	}

	public function test_hydrate_classifies_pdf_and_other_files(): void {
		$pdf  = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/pdf', 'file' => 'sample.pdf', 'guid' => 'http://example.org/wp-content/uploads/sample.pdf' ) );
		$data = Attachment::hydrate( (object) array( 'id' => 1, 'attachment_id' => $pdf ) );
		$this->assertSame( 'pdf', $data['type'] );
		$this->assertFalse( $data['is_image'] );

		$zip  = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/zip', 'file' => 'sample.zip', 'guid' => 'http://example.org/wp-content/uploads/sample.zip' ) );
		$data = Attachment::hydrate( (object) array( 'id' => 2, 'attachment_id' => $zip ) );
		$this->assertSame( 'file', $data['type'] );
	}

	public function test_hydrate_returns_null_when_the_media_item_is_gone(): void {
		$data = Attachment::hydrate( (object) array( 'id' => 1, 'attachment_id' => 999999999 ) );
		$this->assertNull( $data, 'A link whose media was deleted must hydrate to null, not a broken card.' );
	}

	// ── payload_for ─────────────────────────────────────────────────────────

	public function test_payload_for_returns_hydrated_items_and_skips_gone_media(): void {
		$aid = $this->img();
		Attachment::link( 'post', $this->oid, $aid, 0 );
		Attachment::link( 'post', $this->oid, 999999999, 1 ); // media gone → must be skipped

		$payload = Attachment::payload_for( 'post', $this->oid );

		$this->assertCount( 1, $payload, 'payload_for() must drop the link whose media is gone.' );
		$this->assertSame( $aid, (int) $payload[0]['id'] );
	}

	public function test_payload_for_applies_the_rest_attachment_data_filter(): void {
		$aid = $this->img();
		Attachment::link( 'post', $this->oid, $aid, 0 );

		$seen = array();
		$cb   = static function ( $data, $object_type, $object_id ) use ( &$seen ) {
			$seen         = array( $object_type, $object_id );
			$data['url']  = 'FILTERED';
			$data['extra'] = 'pro';
			return $data;
		};
		add_filter( 'jetonomy_rest_attachment_data', $cb, 10, 3 );
		$payload = Attachment::payload_for( 'post', $this->oid );
		remove_filter( 'jetonomy_rest_attachment_data', $cb, 10 );

		$this->assertSame( 'FILTERED', $payload[0]['url'], 'Pro enriches each item through jetonomy_rest_attachment_data — the filter must run.' );
		$this->assertSame( 'pro', $payload[0]['extra'] );
		$this->assertSame( array( 'post', $this->oid ), $seen, 'The filter must receive the object type and id as context.' );
	}

	public function test_payload_for_is_empty_for_an_object_with_no_attachments(): void {
		$this->assertSame( array(), Attachment::payload_for( 'post', $this->oid ) );
	}
}
