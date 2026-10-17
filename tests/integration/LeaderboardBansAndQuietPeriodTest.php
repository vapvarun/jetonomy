<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;

/**
 * REGRESSION (Basecamp 10415690364): a member banned site-wide still held a
 * rank on the leaderboard page, its total, REST and rank_for_user().
 * REGRESSION (Basecamp 10415690405): a quiet week said "No members yet." while
 * All time ranked members.
 */
class LeaderboardBansAndQuietPeriodTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		Restriction::reset_memo();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_user_profiles" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** A member with reputation, last seen $days_ago days back. */
	private function ranked( int $reputation, int $days_ago = 0 ): int {
		global $wpdb;
		$id = self::factory()->user->create();
		UserProfile::find_or_create( $id );
		$wpdb->update(
			$wpdb->prefix . 'jt_user_profiles',
			array(
				'reputation'   => $reputation,
				'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
			),
			array( 'user_id' => $id )
		);
		\Jetonomy\Cache::flush();
		return $id;
	}

	private function ids( string $period ): array {
		return array_map( static fn( $r ) => (int) $r->user_id, UserProfile::list_for_leaderboard( $period, 50, 0 ) );
	}

	private function rest_ids( string $period ): array {
		\Jetonomy\Cache::flush();
		$req = new \WP_REST_Request( 'GET', '/jetonomy/v1/leaderboards' );
		$req->set_param( 'period', $period );
		$res = rest_do_request( $req );
		return array_map( static fn( $i ) => (int) $i['user_id'], $res->get_data()['data'] ?? array() );
	}

	public function test_a_site_banned_member_holds_no_rank_anywhere(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$good   = $this->ranked( 50 );
		$banned = $this->ranked( 120 );
		Restriction::ban( $banned, 'global_ban', $admin );
		Restriction::reset_memo();

		foreach ( array( 'all', 'week' ) as $period ) {
			$this->assertNotContains( $banned, $this->ids( $period ), "banned member ranks on {$period}" );
			$this->assertContains( $good, $this->ids( $period ), "unbanned member lost their rank on {$period}" );
			$this->assertSame( 1, UserProfile::count_for_leaderboard( $period ), "total counts the banned member on {$period}" );
			$this->assertSame( array( $good ), $this->rest_ids( $period ), "REST disagrees with the page on {$period}" );
		}
		$this->assertSame( 0, UserProfile::rank_for_user( $banned, 'all' ) );
		$this->assertSame( 1, UserProfile::rank_for_user( $good, 'all' ), 'the banned member still pushes others down' );
	}

	/**
	 * Negative cases: a space ban or an expired ban does not remove a rank, and
	 * a banned member with reputation 0 was never ranked.
	 *
	 * @covers-existing
	 */
	public function test_space_and_expired_bans_keep_the_rank(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$spaced  = $this->ranked( 30 );
		$expired = $this->ranked( 20 );
		$zero    = $this->ranked( 0 );
		Restriction::ban( $spaced, 'space_ban', $admin, 7 );
		Restriction::ban( $expired, 'global_ban', $admin, null, null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		Restriction::ban( $zero, 'global_ban', $admin );
		Restriction::reset_memo();

		$this->assertSame( array( $spaced, $expired ), $this->ids( 'all' ) );
	}

	private function board( string $period ): string {
		$_GET = array( 'period' => $period );
		ob_start();
		include JETONOMY_DIR . 'templates/views/leaderboard.php';
		$html = (string) ob_get_clean();
		$_GET = array();
		return $html;
	}

	public function test_a_quiet_week_says_no_activity_not_no_members(): void {
		$this->ranked( 80, 40 ); // Ranked all time, not seen this week or month.

		$week = $this->board( 'week' );
		$this->assertStringContainsString( 'No activity this week.', $week );
		$this->assertStringNotContainsString( 'yet.', $week );
		$this->assertStringNotContainsString( 'jt-leader-head', $week, 'an empty period rendered a table' );

		$this->assertStringContainsString( 'No activity this month.', $this->board( 'month' ) );
	}

	/**
	 * Negative case: a community with nobody ranked still says so.
	 *
	 * @covers-existing
	 */
	public function test_a_brand_new_community_still_says_no_members_yet(): void {
		$this->assertStringContainsString( 'yet.', $this->board( 'week' ) );
		$this->assertStringNotContainsString( 'No activity', $this->board( 'week' ) );
	}
}
