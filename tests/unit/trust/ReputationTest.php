<?php
namespace Jetonomy\Tests\Unit\Trust;

use WP_UnitTestCase;
use Jetonomy\Trust\Reputation;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

class ReputationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_points_for_post_upvoted(): void {
		$this->assertEquals( 10, Reputation::points_for( 'post_upvoted' ) );
	}

	public function test_points_for_reply_upvoted(): void {
		$this->assertEquals( 5, Reputation::points_for( 'reply_upvoted' ) );
	}

	public function test_points_for_reply_accepted(): void {
		$this->assertEquals( 15, Reputation::points_for( 'reply_accepted' ) );
	}

	public function test_points_for_idea_planned(): void {
		$this->assertEquals( 20, Reputation::points_for( 'idea_planned' ) );
	}

	public function test_points_for_downvoted(): void {
		$this->assertEquals( -2, Reputation::points_for( 'downvoted' ) );
	}

	public function test_points_for_flag_validated(): void {
		$this->assertEquals( 5, Reputation::points_for( 'flag_validated' ) );
	}

	public function test_points_for_post_reported(): void {
		$this->assertEquals( -10, Reputation::points_for( 'post_reported' ) );
	}

	public function test_points_for_post_removed(): void {
		$this->assertEquals( -20, Reputation::points_for( 'post_removed' ) );
	}

	public function test_points_for_unknown_action_returns_zero(): void {
		$this->assertEquals( 0, Reputation::points_for( 'some_unknown_action' ) );
	}

	public function test_award_calls_adjust_reputation(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		Reputation::award( $user_id, 'post_upvoted' );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 10, (int) $profile->reputation );
	}

	public function test_award_returns_delta(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$delta = Reputation::award( $user_id, 'reply_accepted' );
		$this->assertEquals( 15, $delta );
	}

	public function test_award_negative_action_deducts_reputation(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::adjust_reputation( $user_id, 50 ); // Start at 50.

		Reputation::award( $user_id, 'downvoted' );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 48, (int) $profile->reputation );
	}

	public function test_award_unknown_action_returns_zero_and_does_not_modify_reputation(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::adjust_reputation( $user_id, 30 );

		$delta = Reputation::award( $user_id, 'nonexistent_action' );

		$this->assertEquals( 0, $delta );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 30, (int) $profile->reputation );
	}

	public function test_award_fires_reputation_changed_action(): void {
		$user_id   = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$fired     = false;
		$captured  = [];

		add_action( 'jetonomy_reputation_changed', function( $uid, $action, $delta ) use ( &$fired, &$captured ) {
			$fired    = true;
			$captured = compact( 'uid', 'action', 'delta' );
		}, 10, 3 );

		Reputation::award( $user_id, 'post_upvoted' );

		$this->assertTrue( $fired );
		$this->assertEquals( $user_id, $captured['uid'] );
		$this->assertEquals( 'post_upvoted', $captured['action'] );
		$this->assertEquals( 10, $captured['delta'] );
	}

	public function test_award_unknown_action_does_not_fire_reputation_changed(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$fired   = false;

		add_action( 'jetonomy_reputation_changed', function() use ( &$fired ) {
			$fired = true;
		} );

		Reputation::award( $user_id, 'unknown_action' );

		$this->assertFalse( $fired );
	}
}
