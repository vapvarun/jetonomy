<?php
namespace Jetonomy\Tests\Unit\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\UserProfile;

/**
 * Coverage for the `jetonomy_leaderboard_items` filter (consumed by
 * WB Gamification to enrich leaderboard rows without a second round-trip).
 */
class LeaderboardItemsFilterTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		do_action( 'rest_api_init' );
	}

	public function test_leaderboard_items_filter_enriches_rows(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		wp_set_current_user( $user_id );

		$cb = function ( $items, $request ) {
			$this->assertInstanceOf( WP_REST_Request::class, $request );
			foreach ( $items as &$row ) {
				$row['wb_gam_level'] = 'gold';
			}
			return $items;
		};
		add_filter( 'jetonomy_leaderboard_items', $cb, 10, 2 );

		$request  = new WP_REST_Request( 'GET', '/jetonomy/v1/leaderboards' );
		$response = rest_do_request( $request );
		remove_filter( 'jetonomy_leaderboard_items', $cb, 10 );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'data', $data );
		foreach ( (array) $data['data'] as $row ) {
			$this->assertSame( 'gold', $row['wb_gam_level'] ?? null, 'Filter-injected key must survive to the response' );
		}
	}
}
