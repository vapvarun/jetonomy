<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Tag;

/**
 * A client can page past the first 100 tags.
 *
 * GET /tags accepted a `limit` capped at 100 and hard-coded `offset => 0`,
 * while returning a real COUNT(*) as the total. The response therefore
 * advertised a total the caller could never reach: tag 101 was unreachable at
 * every parameter combination, and the truncation was silent - the mobile app
 * fetched one alphabetical page and had no way to know more existed. A site
 * importing a large forum passes 100 tags easily (Basecamp 10252924147).
 *
 * Tag::list_popular() gained the offset as a defaulted second argument so the
 * two existing callers that pass only a limit are unaffected; that default is
 * pinned here too, because silently changing their behaviour would be a
 * regression of its own.
 */
class TagListPopularOffsetTest extends WP_UnitTestCase {

	/** @var int[] Tag ids in descending post_count order - the order list_popular returns. */
	private array $ordered_ids = array();

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		// Distinct, strictly-descending post_counts so the expected order is
		// unambiguous and a paging bug cannot hide behind ties.
		for ( $i = 0; $i < 12; $i++ ) {
			$id = Tag::find_or_create( sprintf( 'Offset Tag %02d %s', $i, uniqid() ) );

			// find_or_create() does not take a post_count, and list_popular()
			// orders by it, so set it directly to make the expected order exact.
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'jt_tags', array( 'post_count' => 100 - $i ), array( 'id' => $id ) );

			$this->ordered_ids[] = (int) $id;
		}
	}

	public function test_offset_defaults_to_zero_for_existing_callers(): void {
		$first = Tag::list_popular( 5 );

		$this->assertCount( 5, $first );
		$this->assertSame(
			$this->ordered_ids[0],
			(int) $first[0]->id,
			'Called with only a limit, list_popular must still start at the most popular tag.'
		);
	}

	public function test_offset_advances_the_window(): void {
		$second = Tag::list_popular( 5, 5 );

		$this->assertCount( 5, $second );
		$this->assertSame(
			$this->ordered_ids[5],
			(int) $second[0]->id,
			'offset=5 must start at the sixth tag.'
		);
	}

	/**
	 * The actual defect: pages must TILE the set. Overlapping pages would still
	 * look plausible per-page while duplicating rows and hiding others.
	 */
	public function test_pages_do_not_overlap_and_cover_everything(): void {
		$page1 = array_map( fn( $t ) => (int) $t->id, Tag::list_popular( 5, 0 ) );
		$page2 = array_map( fn( $t ) => (int) $t->id, Tag::list_popular( 5, 5 ) );
		$page3 = array_map( fn( $t ) => (int) $t->id, Tag::list_popular( 5, 10 ) );

		$this->assertSame( array(), array_intersect( $page1, $page2 ), 'Pages 1 and 2 must not overlap.' );
		$this->assertSame( array(), array_intersect( $page2, $page3 ), 'Pages 2 and 3 must not overlap.' );

		$seen = array_merge( $page1, $page2, $page3 );
		$this->assertCount( 12, $seen, 'Three pages of 5 over 12 tags must return every tag exactly once.' );
		$this->assertSame(
			$this->ordered_ids,
			$seen,
			'Walking the pages must reproduce the full ordered set - no gaps, no repeats.'
		);
	}

	public function test_offset_past_the_end_returns_empty_not_an_error(): void {
		$this->assertSame(
			array(),
			Tag::list_popular( 5, 999 ),
			'A client paging past the end should get an empty page, not a failure.'
		);
	}
}
