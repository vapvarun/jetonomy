<?php
/**
 * Search relevance puts title matches first.
 *
 * The test DB has no FULLTEXT index (temporary tables), so MATCH cannot run
 * here; this pins the ranking the query asks MySQL for. The order itself was
 * checked against the seeded community: "Dark mode for the community on
 * phones" ranked last of 6 for "dark mode" on the MATCH score alone, and ranks
 * above the topics titled only "dark-mode" with the phrase boost.
 *
 * @package Jetonomy\Tests\Unit\Search
 */

namespace Jetonomy\Tests\Unit\Search;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Search\Fulltext_Search;

class SearchRankingTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_relevance_ranks_the_title_phrase_then_title_words_then_the_score(): void {
		global $wpdb;
		$sql     = '';
		$capture = static function ( $query ) use ( &$sql ) {
			if ( false !== strpos( $query, 'AS match_score' ) ) {
				$sql = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		$suppress = $wpdb->suppress_errors( true );

		( new Fulltext_Search() )->query(
			array(
				'q'          => 'Dark mode',
				'with_total' => false,
			)
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $capture );

		$this->assertStringContainsString( "(p.title LIKE '%Dark mode%') AS title_phrase", str_replace( $wpdb->placeholder_escape(), '%', $sql ) );
		$this->assertStringContainsString( 'ORDER BY title_phrase DESC, title_hits DESC, match_score DESC', $sql );
	}

	/** The words searched for, as the boolean query and the highlighter both read them. */
	public function test_query_terms_drop_operators_and_short_words(): void {
		$this->assertSame( array( 'dark', 'mode' ), Fulltext_Search::query_terms( '+dark "mode" on' ) );
		$this->assertSame( '+dark* +mode*', Fulltext_Search::build_boolean_query( 'dark mode' ) );
		$this->assertSame( 'QA', Fulltext_Search::build_boolean_query( 'QA' ), 'nothing indexable: the raw query passes through' );
	}
}
