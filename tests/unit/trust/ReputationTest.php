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

	public function test_points_for_post_downvoted(): void {
		$this->assertEquals( -2, Reputation::points_for( 'post_downvoted' ) );
	}

	public function test_points_for_reply_downvoted(): void {
		$this->assertEquals( -2, Reputation::points_for( 'reply_downvoted' ) );
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

	public function test_award_applies_delta_and_fires_hook(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$fired    = false;
		$captured = [];
		add_action(
			'jetonomy_reputation_changed',
			function ( $uid, $action, $delta, $context ) use ( &$fired, &$captured ) {
				$fired    = true;
				$captured = compact( 'uid', 'action', 'delta', 'context' );
			},
			10,
			4
		);

		$result = Reputation::award( $user_id, 'post_upvoted' );

		$this->assertEquals( 10, $result );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 10, (int) $profile->reputation );

		$this->assertTrue( $fired );
		$this->assertEquals( $user_id, $captured['uid'] );
		$this->assertEquals( 'post_upvoted', $captured['action'] );
		$this->assertEquals( 10, $captured['delta'] );
		$this->assertEquals( [], $captured['context'] );
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
		UserProfile::_apply_reputation_delta( $user_id, 50 ); // Start at 50.

		Reputation::award( $user_id, 'post_downvoted' );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 48, (int) $profile->reputation );
	}

	public function test_award_returns_zero_for_unknown_action(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::_apply_reputation_delta( $user_id, 30 );

		$delta = Reputation::award( $user_id, 'nonexistent_action' );

		$this->assertEquals( 0, $delta );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 30, (int) $profile->reputation );
	}

	public function test_award_unknown_action_does_not_fire_reputation_changed(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		$fired = false;

		add_action(
			'jetonomy_reputation_changed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		Reputation::award( $user_id, 'unknown_action' );

		$this->assertFalse( $fired );
	}

	public function test_revoke_applies_negative_delta_and_fires_hook_with_revoked_suffix(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::_apply_reputation_delta( $user_id, 100 );

		$captured = [];
		add_action(
			'jetonomy_reputation_changed',
			function ( $uid, $action, $delta, $context ) use ( &$captured ) {
				$captured = compact( 'uid', 'action', 'delta', 'context' );
			},
			10,
			4
		);

		$result = Reputation::revoke( $user_id, 'post_upvoted' );

		$this->assertEquals( -10, $result );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 90, (int) $profile->reputation );

		$this->assertEquals( 'post_upvoted_revoked', $captured['action'] );
		$this->assertEquals( -10, $captured['delta'] );
		$this->assertEquals( [], $captured['context'] );
	}

	public function test_revoke_negative_action_adds_points_back(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::_apply_reputation_delta( $user_id, 50 );

		// Reverse a downvote -> reputation should go UP by 2.
		$result = Reputation::revoke( $user_id, 'post_downvoted' );

		$this->assertEquals( 2, $result );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 52, (int) $profile->reputation );
	}

	public function test_revoke_returns_zero_for_unknown_action(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$this->assertEquals( 0, Reputation::revoke( $user_id, 'never_awarded' ) );
	}

	public function test_award_custom_applies_arbitrary_delta(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$captured = [];
		add_action(
			'jetonomy_reputation_changed',
			function ( $uid, $action, $delta, $context ) use ( &$captured ) {
				$captured = compact( 'uid', 'action', 'delta', 'context' );
			},
			10,
			4
		);

		$result = Reputation::award_custom( $user_id, 42, 'badge_earned', [ 'badge_id' => 7 ] );

		$this->assertEquals( 42, $result );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 42, (int) $profile->reputation );

		$this->assertEquals( 'badge_earned', $captured['action'] );
		$this->assertEquals( 42, $captured['delta'] );
		$this->assertEquals( [ 'badge_id' => 7 ], $captured['context'] );
	}

	public function test_award_custom_zero_delta_is_noop(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$fired = false;
		add_action(
			'jetonomy_reputation_changed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$result = Reputation::award_custom( $user_id, 0, 'noop' );

		$this->assertEquals( 0, $result );
		$this->assertFalse( $fired );
	}

	public function test_points_map_filter_overrides_scoring(): void {
		$cb = function ( $map ) {
			$map['post_upvoted'] = 25;
			$map['wb_gam_custom_action'] = 7;
			return $map;
		};
		add_filter( 'jetonomy_reputation_points_map', $cb );

		$this->assertEquals( 25, Reputation::points_for( 'post_upvoted' ) );
		$this->assertEquals( 7, Reputation::points_for( 'wb_gam_custom_action' ) );

		remove_filter( 'jetonomy_reputation_points_map', $cb );
		$this->assertEquals( 10, Reputation::points_for( 'post_upvoted' ) );
	}

	public function test_pre_change_filter_can_veto_award(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$cb = '__return_zero';
		add_filter( 'jetonomy_reputation_pre_change', $cb );

		$fired = false;
		add_action(
			'jetonomy_reputation_changed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$result = Reputation::award( $user_id, 'post_upvoted' );
		remove_filter( 'jetonomy_reputation_pre_change', $cb );

		$this->assertEquals( 0, $result, 'Vetoed award must return 0' );
		$this->assertFalse( $fired, 'jetonomy_reputation_changed must not fire when vetoed' );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 0, (int) $profile->reputation, 'Vetoed delta must not persist' );
	}

	public function test_pre_change_filter_can_scale_award(): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		$cb = function ( $delta, $uid, $action, $context ) {
			return $delta * 2; // double-points campaign
		};
		add_filter( 'jetonomy_reputation_pre_change', $cb, 10, 4 );

		$result = Reputation::award( $user_id, 'post_upvoted' );
		remove_filter( 'jetonomy_reputation_pre_change', $cb, 10 );

		$this->assertEquals( 20, $result );

		$profile = UserProfile::find_by_user( $user_id );
		$this->assertEquals( 20, (int) $profile->reputation );
	}
}
