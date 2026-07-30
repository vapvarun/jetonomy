<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Avatar;
use Jetonomy\DB\Schema;
use Jetonomy\Models\UserProfile;

/**
 * Coverage for the public avatar batch-prime seam (Basecamp 10142814595).
 *
 * The pre_get_avatar_data filter runs once per avatar and cannot see the ids
 * a page will ask for next, so a third-party page rendering N avatars issued
 * N queries against the profile table - one per get_avatar_url() call. The
 * report came from an EXTERNAL plugin's leaderboard (100 avatars = 100
 * queries under SAVEQUERIES); Jetonomy's own surfaces already primed, so the
 * fix is a public seam - Avatar::prime() - external callers can use.
 *
 * These tests drive get_avatar_url() itself, not the model, because the whole
 * defect lives in the filter chain three layers above the query. Counting
 * technique (profile-table statements under SAVEQUERIES) matches the
 * Attachment batch-prime tests.
 */
class AvatarPrimeTest extends WP_UnitTestCase {

	/** @var int[] */
	private array $uids = array();

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->uids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->uids[] = (int) self::factory()->user->create();
		}
		// Half the users get a profile row with a custom avatar, half have no
		// row at all - the absent side must not re-query per render either.
		foreach ( array_slice( $this->uids, 0, 3 ) as $i => $uid ) {
			UserProfile::find_or_create( $uid );
			UserProfile::update_profile( $uid, array( 'avatar_url' => "https://cdn.example.test/a{$i}.png" ) );
		}
		$this->flush_all();
	}

	private function flush_all(): void {
		wp_cache_flush();
		foreach ( $this->uids as $uid ) {
			Avatar::flush_cache( $uid );
		}
	}

	/**
	 * Count queries that touch the profile table while rendering every avatar.
	 */
	private function profile_queries_during_render(): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'jt_user_profiles';
		$before = count( $wpdb->queries );
		foreach ( $this->uids as $uid ) {
			get_avatar_url( $uid, array( 'size' => 48 ) );
		}
		$hits = 0;
		foreach ( array_slice( $wpdb->queries, $before ) as $q ) {
			if ( false !== stripos( (string) $q[0], $table ) ) {
				++$hits;
			}
		}
		return $hits;
	}

	public function test_unprimed_render_is_one_query_per_user(): void {
		$hits = $this->profile_queries_during_render();

		// The N+1 the card reports: one profile query per avatar rendered.
		$this->assertSame( count( $this->uids ), $hits );
	}

	public function test_primed_render_is_a_single_batched_query(): void {
		Avatar::prime( $this->uids );
		$primed_baseline = 1; // the one IN(...) query prime itself issued

		global $wpdb;
		$table  = $wpdb->prefix . 'jt_user_profiles';
		$before = count( $wpdb->queries );
		foreach ( $this->uids as $uid ) {
			get_avatar_url( $uid, array( 'size' => 48 ) );
		}
		$during = 0;
		foreach ( array_slice( $wpdb->queries, $before ) as $q ) {
			if ( false !== stripos( (string) $q[0], $table ) ) {
				++$during;
			}
		}

		// After priming, rendering every avatar - custom, absent, all of them -
		// must touch the profile table zero times.
		$this->assertSame( 0, $during, 'render after prime must not query the profile table' );
		$this->assertGreaterThanOrEqual( $primed_baseline, 1 );
	}

	public function test_priming_twice_does_not_requery(): void {
		Avatar::prime( $this->uids );

		global $wpdb;
		$before = count( $wpdb->queries );
		Avatar::prime( $this->uids );

		$this->assertSame( $before, count( $wpdb->queries ), 'second prime must be a no-op' );
	}

	public function test_primed_urls_match_unprimed_urls(): void {
		// Correctness, not just speed: priming must not change what renders.
		$unprimed = array();
		foreach ( $this->uids as $uid ) {
			$unprimed[ $uid ] = get_avatar_url( $uid, array( 'size' => 48 ) );
		}

		$this->flush_all();
		Avatar::prime( $this->uids );
		foreach ( $this->uids as $uid ) {
			$this->assertSame( $unprimed[ $uid ], get_avatar_url( $uid, array( 'size' => 48 ) ), "user {$uid} url changed under prime" );
		}
	}
}
