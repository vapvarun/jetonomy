<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\UserProfile;

/**
 * Only reputation > 0 holds a leaderboard position (Basecamp 10335927469).
 * The page list, total, "Your rank", REST and [jetonomy_leaderboard] must all
 * agree on that one population.
 */
class LeaderboardEligibilityTest extends WP_UnitTestCase {

	private int $top;
	private int $low;
	private int $zero;
	private int $negative;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		do_action( 'rest_api_init' );
		\Jetonomy\Cache::flush();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \Jetonomy\table( 'user_profiles' ) ); // phpcs:ignore

		$this->top      = $this->member_with_rep( 50 );
		$this->low      = $this->member_with_rep( 1 );
		$this->zero     = $this->member_with_rep( 0 );
		$this->negative = $this->member_with_rep( -5 );
	}

	private function member_with_rep( int $rep ): int {
		global $wpdb;
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$wpdb->update( \Jetonomy\table( 'user_profiles' ), array( 'reputation' => $rep, 'last_seen_at' => current_time( 'mysql', true ) ), array( 'user_id' => $user_id ) ); // phpcs:ignore
		return $user_id;
	}

	public function test_list_count_and_rank_exclude_zero_and_negative_for_every_period(): void {
		foreach ( array( 'all', 'month', 'week' ) as $period ) {
			$ids = array_map( 'intval', wp_list_pluck( UserProfile::list_for_leaderboard( $period, 50, 0 ), 'user_id' ) );
			$this->assertSame( array( $this->top, $this->low ), $ids, "list ({$period})" );
			$this->assertSame( 2, UserProfile::count_for_leaderboard( $period ), "count ({$period})" );
			$this->assertSame( 0, UserProfile::rank_for_user( $this->zero, $period ), "zero-rep rank ({$period})" );
			$this->assertSame( 0, UserProfile::rank_for_user( $this->negative, $period ), "negative-rep rank ({$period})" );
			$this->assertSame( 2, UserProfile::rank_for_user( $this->low, $period ), "positive rank ({$period})" );
		}
	}

	public function test_rest_total_matches_eligible_population(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/leaderboards' ) )->get_data();
		$this->assertSame( 2, (int) $data['meta']['total'] );
		$this->assertSame( array( $this->top, $this->low ), array_map( 'intval', wp_list_pluck( $data['data'], 'user_id' ) ) );
	}

	public function test_shortcode_lists_only_ranked_members_and_keeps_empty_state(): void {
		$out = do_shortcode( '[jetonomy_leaderboard count="10"]' );
		$this->assertSame( 2, substr_count( $out, '<li>' ) );
		$this->assertStringNotContainsString( get_userdata( $this->zero )->display_name . '</a>', $out );

		global $wpdb;
		$wpdb->query( 'UPDATE ' . \Jetonomy\table( 'user_profiles' ) . ' SET reputation = 0' ); // phpcs:ignore
		// The shortcode shares the leaderboard's 5-minute TTL cache (like GET
		// /leaderboards), so a raw table write is only seen once that expires.
		\Jetonomy\Cache::delete( 'lb:sc:all:10' );
		$this->assertStringContainsString( 'jt-shortcode-empty', do_shortcode( '[jetonomy_leaderboard count="10"]' ) );
	}

	/**
	 * QA J37: ties break newest-member-first (user_id DESC) so the reputation_user
	 * index serves the sort; tied members still share one rank, and the order is a
	 * strict total order, so pages never overlap.
	 */
	public function test_ties_order_by_user_id_desc_and_use_the_index(): void {
		$a = $this->member_with_rep( 20 );
		$b = $this->member_with_rep( 20 );
		$c = $this->member_with_rep( 20 );

		$ids = array_map( 'intval', wp_list_pluck( UserProfile::list_for_leaderboard( 'all', 50, 0 ), 'user_id' ) );
		$this->assertSame( array( $this->top, $c, $b, $a, $this->low ), $ids );

		$page1 = array_map( 'intval', wp_list_pluck( UserProfile::list_for_leaderboard( 'all', 2, 0 ), 'user_id' ) );
		$page2 = array_map( 'intval', wp_list_pluck( UserProfile::list_for_leaderboard( 'all', 2, 2 ), 'user_id' ) );
		$this->assertSame( array( $this->top, $c ), $page1 );
		$this->assertSame( array( $b, $a ), $page2, 'a tie straddling the page boundary neither repeats nor drops a member' );
		$this->assertSame( array( 2, 2, 2 ), array_map( static fn( $u ) => UserProfile::rank_for_user( $u, 'all' ), array( $a, $b, $c ) ) );

		// Rendered: the shortcode board lists the tied members newest first.
		$html = do_shortcode( '[jetonomy_leaderboard limit="5"]' );
		$pos  = array_map( static fn( $u ) => strpos( $html, (string) get_userdata( $u )->display_name ), array( $c, $b, $a ) );
		$this->assertNotContains( false, $pos, 'every tied member is on the rendered board' );
		$sorted = $pos;
		sort( $sorted );
		$this->assertSame( $sorted, $pos, 'rendered order is newest first among ties' );

		$this->assertSame( "'reputation DESC, user_id DESC'", var_export( ( new \ReflectionParameter( array( UserProfile::class, 'list_for_leaderboard' ), 'order_by' ) )->getDefaultValue(), true ), 'both halves DESC, so no mixed-direction filesort' );
	}
}
