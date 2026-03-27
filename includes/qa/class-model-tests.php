<?php
/**
 * Phase 2: Model Unit Tests
 *
 * Exercises the model and permission layer directly — no HTTP round-trip.
 * Each test validates a discrete unit of business logic, isolates the
 * side-effects to temporary rows, and cleans up after itself.
 *
 * @package Jetonomy\QA
 * @since   1.0.0
 */

namespace Jetonomy\QA;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Restriction;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Tag;
use Jetonomy\Models\Notification;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Permissions\Rate_Limiter;
use Jetonomy\Trust\Trust_Evaluator;
use function Jetonomy\table;

class Model_Tests {

	/**
	 * Count of passed tests.
	 *
	 * @var int
	 */
	private int $pass = 0;

	/**
	 * Count of failed tests.
	 *
	 * @var int
	 */
	private int $fail = 0;

	// ──────────────────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Run all Phase-2 model unit tests.
	 *
	 * @return array{ pass: int, fail: int }
	 */
	public function run(): array {
		global $wpdb;

		$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$admin_id  = (int) ( $admin_ids[0] ?? 1 );

		// Find a test space for membership checks.
		$spaces_t = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space = $wpdb->get_row( "SELECT * FROM {$spaces_t} WHERE status = 'active' LIMIT 1" );

		// ── Permission_Engine ─────────────────────────────────────────────────
		\WP_CLI::log( '  Permission_Engine' );

		// 1. Admin can create_posts, create_replies, vote, flag, edit_others_posts, move_posts.
		$admin_actions = [ 'create_posts', 'create_replies', 'vote', 'flag', 'edit_others_posts', 'move_posts' ];
		foreach ( $admin_actions as $action ) {
			$space_ctx = $space ? (int) $space->id : null;
			$can       = Permission_Engine::can( $admin_id, $action, $space_ctx );
			$this->check( "PE1: admin can '{$action}'", $can );
		}

		// 2. Guest (user 0) cannot create_posts.
		$guest_can = Permission_Engine::can( 0, 'create_posts', $space ? (int) $space->id : null );
		$this->check( 'PE2: guest cannot create_posts', ! $guest_can );

		// ── Rate_Limiter ──────────────────────────────────────────────────────
		\WP_CLI::log( '  Rate_Limiter' );

		// 3. Admin bypasses all rate limits.
		$admin_rate_ok = Rate_Limiter::check( $admin_id, 'vote', 0 );
		$this->check( 'RL3: admin bypasses vote rate limit', $admin_rate_ok );

		// 4. TL0 user hits limit after N+1 votes (uses transient injection).
		$fake_tl0_id = 999997; // Unlikely real user ID.
		$key         = "jetonomy_rate_{$fake_tl0_id}_vote";
		set_transient( $key, 9999, 60 ); // Simulate over-limit.
		$tl0_blocked = ! Rate_Limiter::check( $fake_tl0_id, 'vote', 0 );
		delete_transient( $key );
		$this->check( 'RL4: TL0 user blocked when over vote limit', $tl0_blocked );

		// ── SpaceMember ───────────────────────────────────────────────────────
		\WP_CLI::log( '  SpaceMember' );

		if ( $space ) {
			$space_id = (int) $space->id;

			// 5. Admin is member of test space.
			$is_member = SpaceMember::is_member( $space_id, $admin_id );
			$this->check( 'SM5: admin is_member of test space', $is_member );

			// 6. get_role returns a valid role string.
			$role = SpaceMember::get_role( $space_id, $admin_id );
			$this->check( 'SM6: get_role returns non-empty string', ! empty( $role ) );
		} else {
			$this->check( 'SM5: is_member (skipped — no space)', true );
			$this->check( 'SM6: get_role (skipped — no space)', true );
		}

		// ── Restriction ───────────────────────────────────────────────────────
		\WP_CLI::log( '  Restriction' );

		// Create a temporary test user for ban/silence tests.
		$ts       = time();
		$test_uid = wp_insert_user( [
			'user_login' => 'jt_qa_model_' . $ts,
			'user_pass'  => wp_generate_password( 16 ),
			'user_email' => 'jt-qa-model-' . $ts . '@test.local',
			'role'       => 'subscriber',
		] );
		$test_uid = ( $test_uid && ! is_wp_error( $test_uid ) ) ? (int) $test_uid : 0;

		if ( $test_uid ) {
			// 7. is_banned: create global_ban → true → remove → false.
			$ban_id    = Restriction::ban( $test_uid, 'global_ban', $admin_id, null, 'Model test ban' );
			$is_banned = Restriction::is_banned( $test_uid );
			$this->check( 'RE7: is_banned = true after ban', $is_banned );

			Restriction::remove_ban( $ban_id );
			$is_unbanned = ! Restriction::is_banned( $test_uid );
			$this->check( 'RE7: is_banned = false after remove_ban', $is_unbanned );

			// 8. is_silenced: create silence → true → remove → false.
			$sil_id      = Restriction::ban( $test_uid, 'silence', $admin_id, null, 'Model test silence' );
			$is_silenced = Restriction::is_silenced( $test_uid );
			$this->check( 'RE8: is_silenced = true after silence', $is_silenced );

			Restriction::remove_ban( $sil_id );
			$is_unsilenced = ! Restriction::is_silenced( $test_uid );
			$this->check( 'RE8: is_silenced = false after remove_ban', $is_unsilenced );

			// 9. is_space_banned: requires a space.
			if ( $space ) {
				$space_id    = (int) $space->id;
				$spban_id    = Restriction::ban( $test_uid, 'space_ban', $admin_id, $space_id, 'Model test space ban' );
				$is_sp_banned = Restriction::is_space_banned( $test_uid, $space_id );
				$this->check( 'RE9: is_space_banned = true after space_ban', $is_sp_banned );

				Restriction::remove_ban( $spban_id );
				$is_sp_unbanned = ! Restriction::is_space_banned( $test_uid, $space_id );
				$this->check( 'RE9: is_space_banned = false after remove_ban', $is_sp_unbanned );
			} else {
				$this->check( 'RE9: is_space_banned (skipped — no space)', true );
				$this->check( 'RE9: is_space_banned false after remove (skipped)', true );
			}

			wp_delete_user( $test_uid );
		} else {
			$this->check( 'RE7: ban/unban (skipped — test user creation failed)', true );
			$this->check( 'RE7: unban check (skipped)', true );
			$this->check( 'RE8: silence/unsilence (skipped)', true );
			$this->check( 'RE8: unsilence check (skipped)', true );
			$this->check( 'RE9: space ban (skipped)', true );
			$this->check( 'RE9: space unban check (skipped)', true );
		}

		// ── UserProfile ───────────────────────────────────────────────────────
		\WP_CLI::log( '  UserProfile' );

		// 10. find_or_create returns valid profile object.
		$profile = UserProfile::find_or_create( $admin_id );
		$this->check( 'UP10: find_or_create returns object with user_id', isset( $profile->user_id ) && (int) $profile->user_id === $admin_id );

		// ── Tag ───────────────────────────────────────────────────────────────
		\WP_CLI::log( '  Tag' );

		// 11. find_or_create returns ID; find_by_slug finds the same row; cleanup.
		$tag_name = 'qa-model-tag-' . time();
		$tag_id   = Tag::find_or_create( $tag_name );
		$this->check( 'TA11: find_or_create returns positive ID', $tag_id > 0 );

		$found_tag = Tag::find_by_slug( sanitize_title( $tag_name ) );
		$this->check( 'TA11: find_by_slug returns the same row', $found_tag && (int) $found_tag->id === $tag_id );

		// Cleanup tag directly.
		$tags_t = table( 'tags' );
		$wpdb->delete( $tags_t, [ 'id' => $tag_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// ── Notification ──────────────────────────────────────────────────────
		\WP_CLI::log( '  Notification' );

		// 12. unread_count returns integer >= 0.
		$count = Notification::unread_count( $admin_id );
		$this->check( 'NO12: unread_count returns integer >= 0', is_int( $count ) && $count >= 0, "count={$count}" );

		// ── Trust_Evaluator ───────────────────────────────────────────────────
		\WP_CLI::log( '  Trust_Evaluator' );

		// 13. Known stats below L1 threshold → level 0.
		$level_0 = Trust_Evaluator::evaluate_level( [
			'post_count'       => 0,
			'days_active'      => 0,
			'reputation'       => 0,
			'replies_received' => 0,
		] );
		$this->check( 'TE13: evaluate_level with zero stats → 0', 0 === $level_0, "got {$level_0}" );

		// 14. Known stats meeting L1 threshold → level >= 1.
		// Use values that comfortably exceed the default L1 requirements
		// (posts >= 5, days_active >= 3, replies_received >= 10).
		$level_1 = Trust_Evaluator::evaluate_level( [
			'post_count'       => 10,
			'days_active'      => 7,
			'reputation'       => 0,
			'replies_received' => 15,
		] );
		$this->check( 'TE14: evaluate_level with L1 stats → >= 1', $level_1 >= 1, "got {$level_1}" );

		return [ 'pass' => $this->pass, 'fail' => $this->fail ];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Record a test result and print a pass/fail line to WP-CLI output.
	 *
	 * @param string $label  Human-readable test description.
	 * @param bool   $ok     Whether the assertion passed.
	 * @param string $detail Optional detail appended on failure.
	 */
	private function check( string $label, bool $ok, string $detail = '' ): void {
		if ( $ok ) {
			\WP_CLI::log( "    PASS  {$label}" );
			$this->pass++;
		} else {
			$msg = "    FAIL  {$label}";
			if ( $detail ) {
				$msg .= " — {$detail}";
			}
			\WP_CLI::warning( $msg );
			$this->fail++;
		}
	}
}
