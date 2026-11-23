<?php
/**
 * The space grid card: one renderer, one link per card, Follow beside the title.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Space;
use Jetonomy\Models\Subscription;
use WP_UnitTestCase;

/**
 * Markup, follow state, visibility of the button and the query budget.
 */
class SpaceGridRendererTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * @param array<string, mixed> $extra Extra columns.
	 */
	private function space( array $extra = array() ): object {
		$id = (int) Space::create(
			array_merge(
				array(
					'title' => 'Grid ' . uniqid(),
					'slug'  => 'grid-' . uniqid(),
				),
				$extra
			)
		);
		return Space::find( $id );
	}

	private function render( array $spaces, array $args = array() ): string {
		ob_start();
		jetonomy_render_space_grid( $spaces, \Jetonomy\base_url(), '', $args );
		return (string) ob_get_clean();
	}

	public function test_cards_are_list_items_with_one_link_and_the_follow_state(): void {
		$followed = $this->space();
		$other    = $this->space();
		$user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		Subscription::subscribe( $user, 'space', (int) $followed->id );

		$html = $this->render( array( $followed, $other ) );

		$this->assertStringContainsString( '<ul class="jt-space-grid" role="list">', $html );
		$this->assertSame( 2, substr_count( $html, '<li class="jt-card jt-space-card' ) );
		$this->assertSame( 2, substr_count( $html, '<a ' ), 'The title is the only link in a card.' );
		$this->assertSame( 2, substr_count( $html, 'jt-follow-space-btn' ) );
		$this->assertMatchesRegularExpression( '/data-space-id="' . (int) $followed->id . '"\s+data-following="1"\s+aria-pressed="true"/', $html );
		$this->assertMatchesRegularExpression( '/data-space-id="' . (int) $other->id . '"\s+data-following="0"\s+aria-pressed="false"/', $html );
		$this->assertStringNotContainsString( 'jt-space-card-inner', $html );
	}

	public function test_each_follow_button_names_its_space_and_carries_both_names(): void {
		$followed = $this->space( array( 'title' => 'Design' ) );
		$other    = $this->space( array( 'title' => 'Announcements' ) );
		$user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		Subscription::subscribe( $user, 'space', (int) $followed->id );

		$html = $this->render( array( $followed, $other ) );

		$this->assertMatchesRegularExpression( '/aria-pressed="true"\s+aria-label="Following Design"/', $html );
		$this->assertMatchesRegularExpression( '/aria-pressed="false"\s+aria-label="Follow Announcements"/', $html );
		$this->assertStringContainsString( 'data-label-off="Follow Announcements"', $html );
		$this->assertStringContainsString( 'data-label-on="Following Announcements"', $html );
	}

	public function test_no_follow_button_for_a_guest(): void {
		wp_set_current_user( 0 );
		$html = $this->render( array( $this->space() ) );
		$this->assertStringNotContainsString( 'jt-follow-space-btn', $html );
		$this->assertStringContainsString( 'jt-space-card-link', $html );
	}

	public function test_no_follow_button_on_a_listed_space_the_viewer_cannot_read(): void {
		$private = $this->space( array( 'visibility' => 'private' ) );
		$open    = $this->space();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$html = $this->render( array( $private, $open ) );
		$this->assertSame( 1, substr_count( $html, 'jt-follow-space-btn' ), 'Only the space the viewer can read offers Follow.' );
		$this->assertStringContainsString( 'data-space-id="' . (int) $open->id . '"', $html );
		$this->assertStringNotContainsString( 'data-space-id="' . (int) $private->id . '"', $html );
	}

	public function test_cover_strip_only_when_the_space_has_one(): void {
		wp_set_current_user( 0 );
		$with    = $this->space( array( 'cover_image' => 'https://example.com/cover.jpg' ) );
		$without = $this->space();
		$html    = $this->render( array( $with, $without ) );
		$this->assertSame( 1, substr_count( $html, 'class="jt-space-card-cover"' ) );
		$this->assertSame( 1, substr_count( $html, 'jt-space-card--cover' ) );
		$this->assertStringContainsString( 'src="https://example.com/cover.jpg" alt="" width="600" height="144" loading="lazy"', $html );
	}

	public function test_search_options_highlight_trim_and_heading_level(): void {
		wp_set_current_user( 0 );
		$space = $this->space( array( 'description' => 'one two three four five six' ) );
		$html  = $this->render(
			array( $space ),
			array(
				'highlight'     => static fn( string $t ): string => '<mark>' . esc_html( $t ) . '</mark>',
				'excerpt_words' => 2,
				'heading_level' => 4,
			)
		);
		$this->assertStringContainsString( '<h4 class="jt-space-card-title">', $html );
		$this->assertStringContainsString( '<mark>one two&hellip;</mark>', $html );
	}

	public function test_thirty_cards_cost_one_subscriptions_query_and_no_per_card_queries(): void {
		global $wpdb;
		$spaces = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$spaces[] = $this->space();
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$count = static function ( array $set ) use ( $wpdb ): array {
			\Jetonomy\Cache::flush();
			wp_cache_flush();
			$seen = array();
			$spy  = static function ( $sql ) use ( &$seen ) {
				$seen[] = (string) $sql;
				return $sql;
			};
			add_filter( 'query', $spy );
			ob_start();
			jetonomy_render_space_grid( $set, \Jetonomy\base_url() );
			ob_end_clean();
			remove_filter( 'query', $spy );
			$subs = array_filter( $seen, static fn( string $q ): bool => false !== strpos( $q, $wpdb->prefix . 'jt_subscriptions' ) );
			return array( count( $seen ), count( $subs ) );
		};

		list( $few, $few_subs ) = $count( array_slice( $spaces, 0, 3 ) );
		list( $all, $all_subs ) = $count( $spaces );
		$this->assertSame( 1, $all_subs, 'Follow state for every card comes from one query.' );
		$this->assertSame( 1, $few_subs );
		$this->assertSame( $few, $all, 'Thirty cards run no more queries than three.' );
	}
}
