<?php
namespace Jetonomy\Tests\Unit\Trust;

use WP_UnitTestCase;
use Jetonomy\Cron;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

/**
 * The trust sweep feeds the evaluator, so what counts as a "post" lives here, not in
 * Trust_Evaluator: topics plus replies written. TrustEvaluatorTest cannot see that.
 */
class TrustSweepTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		// Default thresholds: nothing saved from the Permissions screen.
		$settings = get_option( 'jetonomy_settings', array() );
		unset( $settings['trust_thresholds'] );
		update_option( 'jetonomy_settings', $settings );
	}

	/**
	 * A member with the given activity, five days old.
	 *
	 * @param int $topics  Topics written.
	 * @param int $replies Replies written.
	 * @param int $level   Starting trust level.
	 * @return int User id.
	 */
	private function member( int $topics, int $replies, int $level = 0 ): int {
		global $wpdb;
		$user_id = self::factory()->user->create();
		UserProfile::find_or_create( $user_id );
		UserProfile::update_profile(
			$user_id,
			array(
				'post_count'  => $topics,
				'reply_count' => $replies,
				'reputation'  => 0,
				'trust_level' => $level,
			)
		);
		$wpdb->update( $wpdb->prefix . 'jt_user_profiles', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ), array( 'user_id' => $user_id ) );
		return $user_id;
	}

	private function level_after_sweep( int $user_id ): int {
		Cron::run_trust_batch( $user_id - 1, 1 );
		\Jetonomy\Cache::flush();
		return (int) UserProfile::find_by_user( $user_id )->trust_level;
	}

	public function test_a_member_who_only_wrote_replies_is_promoted(): void {
		$this->assertSame( 1, $this->level_after_sweep( $this->member( 0, 5 ) ) );
	}

	public function test_a_member_who_only_wrote_topics_is_promoted(): void {
		$this->assertSame( 1, $this->level_after_sweep( $this->member( 5, 0 ) ) );
	}

	public function test_topics_and_replies_add_up(): void {
		$this->assertSame( 1, $this->level_after_sweep( $this->member( 2, 3 ) ) );
	}

	/**
	 * Negative control: pins behaviour that already worked, so it passes before and after the change.
	 *
	 * @covers-existing
	 */
	public function test_four_contributions_are_not_enough(): void {
		$this->assertSame( 0, $this->level_after_sweep( $this->member( 2, 2 ) ) );
	}

	/**
	 * Negative control: pins behaviour that already worked, so it passes before and after the change.
	 *
	 * @covers-existing
	 */
	public function test_the_sweep_never_lowers_a_level(): void {
		// Level 2 set by hand for a member with no activity at all.
		$this->assertSame( 2, $this->level_after_sweep( $this->member( 0, 0, 2 ) ) );
	}
}
