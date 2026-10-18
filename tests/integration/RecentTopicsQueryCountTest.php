<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Shortcodes;

/**
 * REGRESSION (Basecamp 10415690172): Recent topics (and trending) set to 50
 * loaded each author's user row and profile per row, 270 queries for one list.
 * The query count must not grow with the number of rows.
 */
class RecentTopicsQueryCountTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->space = (int) Space::create(
			array(
				'title'      => 'Busy space',
				'slug'       => 'busy-' . uniqid(),
				'visibility' => 'public',
			),
			$admin
		);
		// 50 topics by 50 different members, so every byline is its own user.
		for ( $i = 0; $i < 50; $i++ ) {
			$author = self::factory()->user->create( array( 'display_name' => 'Author ' . $i ) );
			// A member whose Gravatar was already checked: the one-off background
			// check is first-sight warming, not the steady per-row cost measured here.
			update_user_meta( $author, 'jetonomy_has_gravatar', '0' );
			Post::create(
				array(
					'space_id'  => $this->space,
					'author_id' => $author,
					'title'     => 'Topic number ' . $i,
					'slug'      => 'topic-' . $i . '-' . uniqid(),
					'content'   => 'body',
					'status'    => 'publish',
				)
			);
		}
	}

	/** Render cold (no object cache, no request memo) and count the queries. */
	private function queries_for( callable $render ): array {
		global $wpdb;
		wp_cache_flush();
		\Jetonomy\Cache::flush();
		$before = $wpdb->num_queries;
		$html   = $render();
		return array( $wpdb->num_queries - $before, $html );
	}

	public function test_recent_topics_query_count_does_not_grow_with_rows(): void {
		[ $five ]        = $this->queries_for( static fn() => Shortcodes::recent_posts( array( 'count' => 5 ) ) );
		[ $fifty, $html ] = $this->queries_for( static fn() => Shortcodes::recent_posts( array( 'count' => 50 ) ) );

		$this->assertSame( 50, substr_count( $html, 'jt-shortcode-post-title' ) );
		$this->assertLessThan( 5, $fifty - $five, "50 rows cost {$fifty} queries against {$five} for 5 rows: a query per row" );
	}

	public function test_trending_topics_query_count_does_not_grow_with_rows(): void {
		[ $five ]         = $this->queries_for( static fn() => Shortcodes::trending_posts( array( 'count' => 5, 'window' => 30 ) ) );
		[ $fifty, $html ] = $this->queries_for( static fn() => Shortcodes::trending_posts( array( 'count' => 50, 'window' => 30 ) ) );

		$this->assertSame( 50, substr_count( $html, 'jt-shortcode-post-title' ) );
		$this->assertLessThan( 5, $fifty - $five, "50 rows cost {$fifty} queries against {$five} for 5 rows: a query per row" );
	}

	/**
	 * Negative case: count=5 still renders five titles, each with its author.
	 *
	 * @covers-existing
	 */
	public function test_five_still_renders_titles_and_authors(): void {
		$html = Shortcodes::recent_posts( array( 'count' => 5 ) );

		$this->assertSame( 5, substr_count( $html, 'jt-shortcode-post-title' ) );
		$this->assertMatchesRegularExpression( '/Topic number 4[0-9]/', $html );
		$this->assertMatchesRegularExpression( '/Author 4[0-9]/', $html );
	}
}
