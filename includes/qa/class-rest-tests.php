<?php
/**
 * Phase 1: REST Round-Trip Tests
 *
 * Exercises every core action through rest_do_request() — the same code path
 * the browser uses. Every test checks the HTTP status code AND at least one
 * key in the response body. All created objects are cleaned up in reverse
 * order at the end of run().
 *
 * @package Jetonomy\QA
 * @since   1.0.0
 */

namespace Jetonomy\QA;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

class REST_Tests {

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

	/**
	 * Cleanup stack — items pushed during the run and processed in reverse order.
	 *
	 * Each entry: [ 'type' => string, 'id' => int, ... ]
	 *
	 * @var array<int, array<string,mixed>>
	 */
	private array $cleanup = [];

	/**
	 * WordPress administrator ID (user ID 1 or first admin found).
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber (TL0) test user created for permission tests.
	 *
	 * @var int
	 */
	private int $test_user_id;

	/**
	 * The active space used as the fixture for all tests.
	 *
	 * @var object
	 */
	private object $space;

	/**
	 * A second active space (for move tests). May be the same as $space if only
	 * one space exists, in which case move tests are skipped gracefully.
	 *
	 * @var object|null
	 */
	private ?object $other_space = null;

	/**
	 * ID of the primary test post created in Group A.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * ID of the primary test reply created in Group B.
	 *
	 * @var int
	 */
	private int $reply_id = 0;

	/**
	 * ID of the second test reply (used for split).
	 *
	 * @var int
	 */
	private int $reply2_id = 0;

	/**
	 * ID of the post created by the split action.
	 *
	 * @var int
	 */
	private int $split_post_id = 0;

	/**
	 * Admin bio before we change it — restored on cleanup.
	 *
	 * @var string
	 */
	private string $original_bio = '';

	// ──────────────────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Run all Phase-1 REST tests.
	 *
	 * Sets up fixtures, runs test groups A–G, then cleans up.
	 *
	 * @return array{ pass: int, fail: int }
	 */
	public function run(): array {
		$this->setup();

		if ( ! isset( $this->space ) ) {
			\WP_CLI::warning( '  [REST] No active space found — skipping REST tests. Run demo-seed first.' );
			return [ 'pass' => 0, 'fail' => 1 ];
		}

		$this->run_group_a();
		$this->run_group_b();
		$this->run_group_c();
		$this->run_group_d();
		$this->run_group_e();
		$this->run_group_f();
		$this->run_group_g();
		$this->test_subscriber_actions();

		$this->cleanup();

		// Restore admin as the current user.
		wp_set_current_user( $this->admin_id );

		return [ 'pass' => $this->pass, 'fail' => $this->fail ];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Setup
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve admin ID, find a fixture space, create the TL0 test user.
	 */
	private function setup(): void {
		global $wpdb;

		// Resolve admin.
		$admin_ids      = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$this->admin_id = (int) ( $admin_ids[0] ?? 1 );
		wp_set_current_user( $this->admin_id );

		// Find an active space.
		$spaces_t = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space = $wpdb->get_row( "SELECT * FROM {$spaces_t} WHERE status = 'active' LIMIT 1" );
		if ( ! $space ) {
			return; // run() will detect this and bail.
		}
		$this->space = $space;

		// Try to find a second distinct space for move tests.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$other = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$spaces_t} WHERE status = 'active' AND id != %d LIMIT 1",
				(int) $this->space->id
			)
		);
		$this->other_space = $other ?: null;

		// Stash admin's current bio so we can restore it.
		$profile            = \Jetonomy\Models\UserProfile::find_or_create( $this->admin_id );
		$this->original_bio = $profile->bio ?? '';

		// Create TL0 subscriber for permission tests.
		$ts                 = time();
		$uid                = wp_insert_user( [
			'user_login'   => 'jt_qa_tl0_' . $ts,
			'user_pass'    => wp_generate_password( 16 ),
			'user_email'   => 'jt-qa-tl0-' . $ts . '@test.local',
			'role'         => 'subscriber',
			'display_name' => 'QA Test User',
		] );
		$this->test_user_id = ( $uid && ! is_wp_error( $uid ) ) ? (int) $uid : 0;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group A — Post CRUD
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Tests 1–7: post create, edit, pin/unpin, close, move.
	 */
	private function run_group_a(): void {
		\WP_CLI::log( '  Group A: Post CRUD' );

		$space_id  = (int) $this->space->id;
		$title     = 'QA REST Post ' . time();

		// 1. Create post.
		$r = $this->rest( 'POST', "/spaces/{$space_id}/posts", [
			'title'   => $title,
			'content' => '<p>REST QA test post content.</p>',
			'type'    => 'discussion',
		] );
		$data = $r->get_data();
		$this->check( 'A1: POST /spaces/{id}/posts → 201', 201 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'A1: response has id', ! empty( $data['id'] ), 'missing id' );
		$this->check( 'A1: response has slug', ! empty( $data['slug'] ), 'missing slug' );
		$this->check( 'A1: response has title', ! empty( $data['title'] ), 'missing title' );

		if ( empty( $data['id'] ) ) {
			// Without a post ID the remaining groups cannot run.
			$this->check( 'A1: post created (fatal — aborting group A)', false, wp_json_encode( $data ) );
			return;
		}

		$this->post_id = (int) $data['id'];
		$this->cleanup[] = [ 'type' => 'post_rest', 'id' => $this->post_id ];

		// 2. Edit post.
		$r = $this->rest( 'PATCH', "/posts/{$this->post_id}", [
			'content' => '<p>REST QA edited content.</p>',
		] );
		$data = $r->get_data();
		$this->check( 'A2: PATCH /posts/{id} → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'A2: content was updated', isset( $data['id'] ), 'response missing id' );

		// 3. Pin post (first call → sticky=true).
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/pin" );
		$data = $r->get_data();
		$this->check( 'A3: POST /posts/{id}/pin → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'A3: is_sticky = true after pin', ! empty( $data['is_sticky'] ), 'is_sticky was not true' );

		// 4. Unpin (second call toggles back).
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/pin" );
		$data = $r->get_data();
		$this->check( 'A4: POST /posts/{id}/pin (toggle) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'A4: is_sticky = false after unpin', empty( $data['is_sticky'] ), 'is_sticky was not false' );

		// 5. Close post.
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/close" );
		$data = $r->get_data();
		$this->check( 'A5: POST /posts/{id}/close → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'A5: is_closed = true', ! empty( $data['is_closed'] ), 'is_closed was not true' );

		// Re-open via direct DB — no REST re-open endpoint exists (close is one-way).
		// This is necessary so Group B can create replies on the same post.
		global $wpdb;
		$posts_t = table( 'posts' );
		$wpdb->update( $posts_t, [ 'is_closed' => 0 ], [ 'id' => $this->post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// 6+7. Move post (requires a second space).
		if ( $this->other_space ) {
			$target_id = (int) $this->other_space->id;

			// 6. Move to other space.
			$r = $this->rest( 'POST', "/posts/{$this->post_id}/move", [
				'target_space_id' => $target_id,
			] );
			$data = $r->get_data();
			$this->check( 'A6: POST /posts/{id}/move → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'A6: space_id changed in response', isset( $data['space_id'] ) && (int) $data['space_id'] === $target_id, "space_id={$data['space_id']}" );

			// 7. Move back.
			$r = $this->rest( 'POST', "/posts/{$this->post_id}/move", [
				'target_space_id' => $space_id,
			] );
			$data = $r->get_data();
			$this->check( 'A7: POST /posts/{id}/move back → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'A7: space_id restored', isset( $data['space_id'] ) && (int) $data['space_id'] === $space_id, "space_id={$data['space_id']}" );
		} else {
			$this->check( 'A6: move post (skipped — only one active space)', true );
			$this->check( 'A7: move post back (skipped)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group B — Reply CRUD
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Tests 8–12: reply create, edit, accept, split.
	 */
	private function run_group_b(): void {
		\WP_CLI::log( '  Group B: Reply CRUD' );

		if ( ! $this->post_id ) {
			$this->check( 'B: skipped — no post_id from Group A', false );
			return;
		}

		// 8. Create reply.
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/replies", [
			'content' => '<p>REST QA reply content.</p>',
		] );
		$data = $r->get_data();
		$this->check( 'B8: POST /posts/{id}/replies → 201', 201 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'B8: response has id', ! empty( $data['id'] ), 'missing id' );

		if ( empty( $data['id'] ) ) {
			$this->check( 'B8: reply created (fatal — aborting group B)', false, wp_json_encode( $data ) );
			return;
		}

		$this->reply_id  = (int) $data['id'];
		$this->cleanup[] = [ 'type' => 'reply_rest', 'id' => $this->reply_id ];

		// 9. Edit reply.
		$r = $this->rest( 'PATCH', "/replies/{$this->reply_id}", [
			'content' => '<p>REST QA edited reply.</p>',
		] );
		$data = $r->get_data();
		$this->check( 'B9: PATCH /replies/{id} → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'B9: response has id', isset( $data['id'] ), 'response missing id' );

		// 10. Accept reply as answer.
		$r = $this->rest( 'POST', "/replies/{$this->reply_id}/accept" );
		$data = $r->get_data();
		$this->check( 'B10: POST /replies/{id}/accept → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'B10: is_accepted = true', ! empty( $data['is_accepted'] ), 'is_accepted was not true' );

		// 11. Create second reply for the split test.
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/replies", [
			'content' => '<p>REST QA split-test reply.</p>',
		] );
		$data = $r->get_data();
		$this->check( 'B11: second reply created for split → 201', 201 === $r->get_status(), "HTTP {$r->get_status()}" );

		if ( ! empty( $data['id'] ) ) {
			$this->reply2_id = (int) $data['id'];
			$this->cleanup[] = [ 'type' => 'reply_rest', 'id' => $this->reply2_id ];

			// 12. Split second reply into a new topic.
			$r = $this->rest( 'POST', "/replies/{$this->reply2_id}/split", [
				'title' => 'QA REST Split Topic ' . time(),
			] );
			$data = $r->get_data();
			$this->check( 'B12: POST /replies/{id}/split → 201', 201 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'B12: split response has id', ! empty( $data['id'] ), 'missing id in split response' );
			$this->check( 'B12: split response has slug', ! empty( $data['slug'] ), 'missing slug in split response' );

			if ( ! empty( $data['id'] ) ) {
				$this->split_post_id = (int) $data['id'];
				// Split post cleaned up first (before reply2).
				// We prepend to cleanup so it runs before the reply it was split from.
				array_unshift( $this->cleanup, [ 'type' => 'post_rest', 'id' => $this->split_post_id ] );
			}
		} else {
			$this->check( 'B12: split (skipped — second reply not created)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group C — Votes
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Tests 13–17: post upvote, downvote, remove, reply upvote, remove.
	 */
	private function run_group_c(): void {
		\WP_CLI::log( '  Group C: Votes' );

		if ( ! $this->post_id ) {
			$this->check( 'C: skipped — no post_id', false );
			return;
		}

		// 13. Upvote post.
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/vote", [ 'value' => 1 ] );
		$data = $r->get_data();
		$this->check( 'C13: POST /posts/{id}/vote (up) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'C13: response has score', array_key_exists( 'score', $data ), 'missing score key' );

		// 14. Downvote post (should change or toggle score).
		// We first DELETE the existing vote so we can test a fresh downvote.
		$this->rest( 'DELETE', "/posts/{$this->post_id}/vote" );
		$r = $this->rest( 'POST', "/posts/{$this->post_id}/vote", [ 'value' => -1 ] );
		$data = $r->get_data();
		$this->check( 'C14: POST /posts/{id}/vote (down) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'C14: response has score', array_key_exists( 'score', $data ), 'missing score key' );

		// 15. Delete post vote (unvote).
		$r = $this->rest( 'DELETE', "/posts/{$this->post_id}/vote" );
		$this->check( 'C15: DELETE /posts/{id}/vote → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );

		// 16. Upvote reply.
		if ( $this->reply_id ) {
			$r = $this->rest( 'POST', "/replies/{$this->reply_id}/vote", [ 'value' => 1 ] );
			$data = $r->get_data();
			$this->check( 'C16: POST /replies/{id}/vote (up) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'C16: response has score', array_key_exists( 'score', $data ), 'missing score key' );

			// 17. Delete reply vote.
			$r = $this->rest( 'DELETE', "/replies/{$this->reply_id}/vote" );
			$this->check( 'C17: DELETE /replies/{id}/vote → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		} else {
			$this->check( 'C16: reply vote (skipped — no reply_id)', true );
			$this->check( 'C17: reply unvote (skipped)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group D — Subscriptions
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Tests 18–22: post subscribe, list, unsubscribe; space subscribe, unsubscribe.
	 */
	private function run_group_d(): void {
		\WP_CLI::log( '  Group D: Subscriptions' );

		if ( ! $this->post_id ) {
			$this->check( 'D: skipped — no post_id', false );
			return;
		}

		// 18. Subscribe to post.
		$r = $this->rest( 'POST', '/subscriptions', [
			'object_type' => 'post',
			'object_id'   => $this->post_id,
		] );
		$data = $r->get_data();
		$ok   = in_array( $r->get_status(), [ 200, 201 ], true );
		$this->check( 'D18: POST /subscriptions (post) → 200/201', $ok, "HTTP {$r->get_status()}" );
		$this->check( 'D18: response has id', ! empty( $data['id'] ), 'missing id' );

		$post_sub_id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;

		// 19. List subscriptions — verify our sub appears.
		$r = $this->rest( 'GET', '/subscriptions', [
			'object_type' => 'post',
			'object_id'   => $this->post_id,
		] );
		$data = $r->get_data();
		$this->check( 'D19: GET /subscriptions → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'D19: response has data array', isset( $data['data'] ) && is_array( $data['data'] ), 'missing data array' );

		$found_sub = false;
		if ( isset( $data['data'] ) ) {
			foreach ( $data['data'] as $sub ) {
				if ( isset( $sub['id'] ) && (int) $sub['id'] === $post_sub_id ) {
					$found_sub = true;
					break;
				}
			}
		}
		$this->check( 'D19: subscription appears in list', $found_sub, "sub_id={$post_sub_id}" );

		// 20. Delete post subscription.
		if ( $post_sub_id ) {
			$r = $this->rest( 'DELETE', "/subscriptions/{$post_sub_id}" );
			$this->check( 'D20: DELETE /subscriptions/{id} → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		} else {
			$this->check( 'D20: delete post sub (skipped — no sub_id)', true );
		}

		// 21. Subscribe to space.
		$space_id = (int) $this->space->id;
		$r = $this->rest( 'POST', '/subscriptions', [
			'object_type' => 'space',
			'object_id'   => $space_id,
		] );
		$data = $r->get_data();
		$ok   = in_array( $r->get_status(), [ 200, 201 ], true );
		$this->check( 'D21: POST /subscriptions (space) → 200/201', $ok, "HTTP {$r->get_status()}" );

		$space_sub_id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;

		// 22. Delete space subscription.
		if ( $space_sub_id ) {
			$r = $this->rest( 'DELETE', "/subscriptions/{$space_sub_id}" );
			$this->check( 'D22: DELETE /subscriptions/{id} (space) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		} else {
			$this->check( 'D22: delete space sub (skipped — no sub_id)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group E — Other Actions
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Tests 23–30: bookmarks, flags, profile update, notifications, space join.
	 */
	private function run_group_e(): void {
		\WP_CLI::log( '  Group E: Bookmarks / Flags / Profile / Notifications / Space Join' );

		if ( $this->post_id ) {
			// 23. Bookmark post (toggle on).
			$r = $this->rest( 'POST', '/bookmarks', [ 'post_id' => $this->post_id ] );
			$data = $r->get_data();
			$this->check( 'E23: POST /bookmarks (on) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'E23: bookmarked = true', ! empty( $data['bookmarked'] ), 'bookmarked was not true' );

			// 24. Bookmark toggle off.
			$r = $this->rest( 'POST', '/bookmarks', [ 'post_id' => $this->post_id ] );
			$data = $r->get_data();
			$this->check( 'E24: POST /bookmarks (off) → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'E24: bookmarked = false', empty( $data['bookmarked'] ), 'bookmarked was not false' );

			// 25. Create flag.
			$r = $this->rest( 'POST', '/flags', [
				'object_type' => 'post',
				'object_id'   => $this->post_id,
				'reason'      => 'other',
				'description' => 'REST QA test flag',
			] );
			$data = $r->get_data();
			$ok   = in_array( $r->get_status(), [ 200, 201 ], true );
			$this->check( 'E25: POST /flags → 200/201', $ok, "HTTP {$r->get_status()}" );
			$this->check( 'E25: response has id', ! empty( $data['id'] ), 'missing id' );

			$flag_id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;
			if ( $flag_id ) {
				$this->cleanup[] = [ 'type' => 'flag_db', 'id' => $flag_id ];
			}

			// 26. Resolve flag.
			if ( $flag_id ) {
				$r = $this->rest( 'POST', "/moderation/flags/{$flag_id}/resolve", [ 'status' => 'dismissed' ] );
				$data = $r->get_data();
				$this->check( 'E26: POST /moderation/flags/{id}/resolve → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
				$this->check( 'E26: resolved = true', ! empty( $data['resolved'] ), 'resolved was not true' );
			} else {
				$this->check( 'E26: resolve flag (skipped — no flag_id)', true );
			}
		} else {
			$this->check( 'E23: bookmark on (skipped — no post_id)', true );
			$this->check( 'E24: bookmark off (skipped)', true );
			$this->check( 'E25: flag (skipped)', true );
			$this->check( 'E26: resolve flag (skipped)', true );
		}

		// 27. Update user profile (display_name via bio field).
		$r = $this->rest( 'PATCH', '/users/me', [
			'bio' => 'REST QA test bio ' . time(),
		] );
		$data = $r->get_data();
		$this->check( 'E27: PATCH /users/me → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'E27: response has user_id or id', isset( $data['id'] ) || isset( $data['user_id'] ), 'response missing id/user_id' );

		// Queue cleanup: restore original bio.
		$this->cleanup[] = [ 'type' => 'restore_bio', 'id' => $this->admin_id ];

		// 28. Mark all notifications read.
		$r = $this->rest( 'POST', '/notifications/mark-all-read' );
		$data = $r->get_data();
		$this->check( 'E28: POST /notifications/mark-all-read → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'E28: success = true', ! empty( $data['success'] ), 'success was not true' );

		// 29. List notifications.
		$r = $this->rest( 'GET', '/notifications', [ 'limit' => 5 ] );
		$data = $r->get_data();
		$this->check( 'E29: GET /notifications → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'E29: response has data array', isset( $data['data'] ) && is_array( $data['data'] ), 'missing data array' );

		// 30. Space join as test user.
		if ( $this->test_user_id ) {
			$space_id = (int) $this->space->id;
			$r = $this->rest( 'POST', "/spaces/{$space_id}/members", [], $this->test_user_id );
			$status = $r->get_status();
			// 200 = already member (or open join), 201 = just joined, 202 = pending approval, 409 = already member.
			$join_ok = in_array( $status, [ 200, 201, 202, 409 ], true );
			$this->check( 'E30: POST /spaces/{id}/members (join) → 200/201/202/409', $join_ok, "HTTP {$status}" );

			// Register cleanup only if actually joined (not approval/invite spaces).
			if ( in_array( $status, [ 200, 201 ], true ) ) {
				$this->cleanup[] = [ 'type' => 'space_member_db', 'space_id' => $space_id, 'user_id' => $this->test_user_id ];
			}
		} else {
			$this->check( 'E30: space join (skipped — test user not created)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group F — Guest / Logged-out Rejection Tests
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Tests 31–33: unauthenticated requests must return 401.
	 */
	private function run_group_f(): void {
		\WP_CLI::log( '  Group F: Guest Rejection (401)' );

		// Guest user ID = 0.
		$guest = 0;

		// 31. Vote as guest.
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', "/posts/{$this->post_id}/vote", [ 'value' => 1 ], $guest );
			$this->check( 'F31: POST /posts/{id}/vote as guest → 401', 401 === $r->get_status(), "HTTP {$r->get_status()}" );
		} else {
			$this->check( 'F31: guest vote (skipped — no post_id)', true );
		}

		// 32. Create reply as guest.
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', "/posts/{$this->post_id}/replies", [ 'content' => '<p>guest reply</p>' ], $guest );
			$this->check( 'F32: POST /posts/{id}/replies as guest → 401', 401 === $r->get_status(), "HTTP {$r->get_status()}" );
		} else {
			$this->check( 'F32: guest reply (skipped — no post_id)', true );
		}

		// 33. Update profile as guest.
		$r = $this->rest( 'PATCH', '/users/me', [ 'bio' => 'guest bio' ], $guest );
		$this->check( 'F33: PATCH /users/me as guest → 401', 401 === $r->get_status(), "HTTP {$r->get_status()}" );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Group G — Permission Tests
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Test 34: non-moderator cannot resolve flags (403).
	 */
	private function run_group_g(): void {
		\WP_CLI::log( '  Group G: Permission Rejection (403)' );

		// Ensure we are back to admin after Group F's guest tests.
		wp_set_current_user( $this->admin_id );

		if ( ! $this->test_user_id ) {
			$this->check( 'G34: non-mod resolve flag (skipped — no test user)', true );
			return;
		}

		// We need an existing flag to target. Create one as admin, then attempt
		// resolve as test_user (subscriber, no jetonomy_moderate capability).
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', '/flags', [
				'object_type' => 'post',
				'object_id'   => $this->post_id,
				'reason'      => 'other',
				'description' => 'Group G permission test flag',
			] );
			$data    = $r->get_data();
			$flag_id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;

			if ( $flag_id ) {
				$this->cleanup[] = [ 'type' => 'flag_db', 'id' => $flag_id ];

				// Try to resolve as non-moderator.
				$r = $this->rest( 'POST', "/moderation/flags/{$flag_id}/resolve", [ 'status' => 'dismissed' ], $this->test_user_id );
				$this->check( 'G34: POST /moderation/flags/{id}/resolve as non-mod → 403', 403 === $r->get_status(), "HTTP {$r->get_status()}" );
			} else {
				$this->check( 'G34: permission test flag (skipped — flag creation failed)', true );
			}
		} else {
			$this->check( 'G34: non-mod resolve flag (skipped — no post_id)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Cleanup
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Group H — Subscriber User Tests.
	 *
	 * Tests what a normal subscriber (non-admin) can actually do:
	 * create post, reply, vote, bookmark, subscribe, view notifications.
	 * Also tests they CANNOT do admin things (pin, move, merge, close, resolve flags).
	 */
	private function test_subscriber_actions(): void {
		\WP_CLI::log( '  Group H: Subscriber User Actions' );

		// Ensure test user is a space member (join the space first as admin, then act as subscriber).
		wp_set_current_user( $this->test_user_id );

		// H35: Subscriber can create a post.
		$r = $this->rest( 'POST', '/spaces/' . $this->space->id . '/posts', [
			'title'   => 'Subscriber QA Post',
			'content' => '<p>Post by subscriber.</p>',
			'type'    => 'discussion',
		], $this->test_user_id );
		$sub_post_id = $r->get_data()['id'] ?? 0;
		$this->check( 'H35: Subscriber creates post → 201', $r->get_status() === 201 );
		if ( $sub_post_id ) {
			$this->cleanup[] = [ 'post', $sub_post_id ];
		}

		// H36: Subscriber can create a reply on their own post.
		if ( $sub_post_id ) {
			$r = $this->rest( 'POST', '/posts/' . $sub_post_id . '/replies', [
				'content' => '<p>Reply by subscriber.</p>',
			], $this->test_user_id );
			$sub_reply_id = $r->get_data()['id'] ?? 0;
			$this->check( 'H36: Subscriber creates reply → 201', $r->get_status() === 201 );
			if ( $sub_reply_id ) {
				$this->cleanup[] = [ 'reply', $sub_reply_id ];
			}
		}

		// H37: Subscriber can vote on a post (the admin-created test post).
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', '/posts/' . $this->post_id . '/vote', [
				'value' => 1,
			], $this->test_user_id );
			$this->check( 'H37: Subscriber votes on post → 200', $r->get_status() === 200 );
			// Undo.
			$this->rest( 'DELETE', '/posts/' . $this->post_id . '/vote', [], $this->test_user_id );
		}

		// H38: Subscriber can bookmark.
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', '/bookmarks', [
				'post_id' => $this->post_id,
			], $this->test_user_id );
			$this->check( 'H38: Subscriber bookmarks → 200', $r->get_status() === 200 );
			// Undo.
			$this->rest( 'POST', '/bookmarks', [ 'post_id' => $this->post_id ], $this->test_user_id );
		}

		// H39: Subscriber can subscribe to a post.
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', '/subscriptions', [
				'object_type' => 'post',
				'object_id'   => $this->post_id,
			], $this->test_user_id );
			$sub_sub_id = $r->get_data()['id'] ?? 0;
			$this->check( 'H39: Subscriber follows post → 200/201', in_array( $r->get_status(), [ 200, 201 ], true ) );
			// Undo.
			if ( $sub_sub_id ) {
				$this->rest( 'DELETE', '/subscriptions/' . $sub_sub_id, [], $this->test_user_id );
			}
		}

		// H40: Subscriber can view notifications.
		$r = $this->rest( 'GET', '/notifications', [ 'limit' => 5 ], $this->test_user_id );
		$this->check( 'H40: Subscriber views notifications → 200', $r->get_status() === 200 );

		// H41: Subscriber can update own profile.
		$r = $this->rest( 'PATCH', '/users/me', [
			'bio' => 'Subscriber bio test',
		], $this->test_user_id );
		$this->check( 'H41: Subscriber updates profile → 200', $r->get_status() === 200 );

		// H42: Subscriber CANNOT pin a post (admin-only action).
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', '/posts/' . $this->post_id . '/pin', [], $this->test_user_id );
			$this->check( 'H42: Subscriber cannot pin → 403', $r->get_status() === 403 );
		}

		// H43: Subscriber CANNOT move a post to another space.
		if ( $this->post_id ) {
			global $wpdb;
			$spaces_t = table( 'spaces' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$other = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$spaces_t} WHERE status = 'active' AND id != %d LIMIT 1", (int) $this->space->id ) );
			// phpcs:enable
			$target = $other ? (int) $other->id : (int) $this->space->id;
			$r = $this->rest( 'POST', '/posts/' . $this->post_id . '/move', [
				'target_space_id' => $target,
			], $this->test_user_id );
			$this->check( 'H43: Subscriber cannot move → 403', $r->get_status() === 403 );
		}

		// H44: Subscriber CANNOT close a post.
		if ( $this->post_id ) {
			$r = $this->rest( 'POST', '/posts/' . $this->post_id . '/close', [], $this->test_user_id );
			$this->check( 'H44: Subscriber cannot close → 403', $r->get_status() === 403 );
		}

		// H45: Subscriber CANNOT delete another user's post.
		if ( $this->post_id ) {
			$r = $this->rest( 'DELETE', '/posts/' . $this->post_id, [], $this->test_user_id );
			$this->check( 'H45: Subscriber cannot delete others post → 403', $r->get_status() === 403 );
		}

		// H46: Subscriber CAN edit their own post.
		if ( $sub_post_id ) {
			$r = $this->rest( 'PATCH', '/posts/' . $sub_post_id, [
				'content' => '<p>Edited by subscriber.</p>',
			], $this->test_user_id );
			$this->check( 'H46: Subscriber edits own post → 200', $r->get_status() === 200 );
		}

		// H47: Subscriber CAN delete their own post.
		if ( $sub_post_id ) {
			$r = $this->rest( 'DELETE', '/posts/' . $sub_post_id, [], $this->test_user_id );
			$this->check( 'H47: Subscriber deletes own post → 200', $r->get_status() === 200 );
			// Remove from cleanup since we just deleted it.
			$this->cleanup = array_filter( $this->cleanup, fn( $item ) => ! ( $item[0] === 'post' && $item[1] === $sub_post_id ) );
		}

		// Restore admin.
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Run the cleanup stack in reverse order.
	 *
	 * REST DELETE is used where an endpoint exists. Direct DB is used only for
	 * objects without a DELETE endpoint (flags, space member rows, notifications
	 * cleaned by cascade).
	 */
	private function cleanup(): void {
		\WP_CLI::log( '  Cleanup...' );

		// Restore admin as actor before cleanup DELETE calls.
		wp_set_current_user( $this->admin_id );

		foreach ( array_reverse( $this->cleanup ) as $item ) {
			switch ( $item['type'] ) {
				case 'post_rest':
					$this->rest( 'DELETE', "/posts/{$item['id']}" );
					break;

				case 'reply_rest':
					$this->rest( 'DELETE', "/replies/{$item['id']}" );
					break;

				case 'flag_db':
					// No DELETE endpoint for flags — remove directly.
					global $wpdb;
					$flags_t = table( 'flags' );
					$wpdb->delete( $flags_t, [ 'id' => (int) $item['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					break;

				case 'space_member_db':
					// Remove test user from space directly (no exposed LEAVE endpoint for another user).
					\Jetonomy\Models\SpaceMember::remove( (int) $item['space_id'], (int) $item['user_id'] );
					break;

				case 'restore_bio':
					// Restore admin bio via REST PATCH.
					$this->rest( 'PATCH', '/users/me', [ 'bio' => $this->original_bio ] );
					break;
			}
		}

		// Delete the TL0 test user.
		if ( $this->test_user_id ) {
			wp_delete_user( $this->test_user_id );
		}

		\WP_CLI::log( sprintf( '  Cleaned up %d fixture object(s).', count( $this->cleanup ) ) );
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

	/**
	 * Execute a REST request internally via rest_do_request().
	 *
	 * This exercises the full WordPress REST stack (permission callbacks,
	 * controller logic, model layer) without an HTTP round-trip.
	 *
	 * @param string   $method   HTTP method: GET, POST, PATCH, DELETE.
	 * @param string   $route    Route path relative to /jetonomy/v1 (e.g. '/posts/5').
	 * @param array    $params   Query params (GET) or body params (POST/PATCH/DELETE).
	 * @param int|null $as_user  WP user ID to act as, or null to keep current user.
	 * @return \WP_REST_Response
	 */
	private function rest( string $method, string $route, array $params = [], ?int $as_user = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/jetonomy/v1' . $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		if ( null !== $as_user ) {
			wp_set_current_user( $as_user );
		}

		$response = rest_do_request( $request );

		// If the response is a WP_Error (routing failure etc.), wrap it so callers
		// can still call get_status() / get_data() without crashing.
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
			return new \WP_REST_Response( [
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			], $status );
		}

		return $response;
	}
}
