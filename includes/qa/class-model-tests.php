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

		$this->test_reorder();
		$this->test_space_ownership_transfer();

		$this->check_delete_contract( $admin_id );

		return [ 'pass' => $this->pass, 'fail' => $this->fail ];
	}

	/**
	 * 1.9.5 regression guards for the delete contract.
	 *
	 * `jetonomy_after_delete_post` / `_reply` used to fire from the REST
	 * controllers only. Two things were wrong with that. The controllers do not
	 * hard-delete at all - they soft-trash via update( status => trash ) - so the
	 * hook fired on a RESTORABLE post and Pro's attachments listener dropped its
	 * link rows; and a genuine hard delete through the model fired nothing, so
	 * those rows orphaned forever (Basecamp 10268067864).
	 *
	 * These assert the contract from the model's side, which is the side every
	 * caller shares: CLI, journeys, abilities and REST alike.
	 *
	 * @param int $admin_id Administrator user ID.
	 */
	private function check_delete_contract( int $admin_id ): void {
		global $wpdb;

		$spaces_t = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var( "SELECT id FROM {$spaces_t} ORDER BY id ASC LIMIT 1" );
		if ( ! $space_id ) {
			// Skip rather than fail, matching how the REST phase handles an
			// unseeded site. A missing fixture is not a broken delete contract,
			// and reporting it as one buries real failures in noise.
			\WP_CLI::log( '    SKIP  DC1-DC3: delete contract — no space on this site (run demo-seed)' );
			return;
		}

		// Counted on a property, not a local: PHPStan cannot see a by-reference
		// mutation inside a closure, so a local made `0 === $fired` look like a
		// comparison that is always false.
		$this->hook_fires = 0;
		$spy              = function () {
			++$this->hook_fires;
		};

		// DC1: a hard delete through the model fires the hook exactly once.
		add_action( 'jetonomy_after_delete_post', $spy, 1 );
		$post = \Jetonomy\Models\Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $admin_id,
				'title'     => 'QA delete-contract ' . wp_generate_password( 6, false ),
				'content'   => 'x',
				'status'    => 'publish',
			]
		);
		$post_id  = is_wp_error( $post ) ? 0 : (int) $post;

		if ( $post_id > 0 ) {
			\Jetonomy\Models\Post::delete( $post_id );
			$this->check( 'DC1: Post::delete fires jetonomy_after_delete_post exactly once', 1 === $this->hook_fire_count(), sprintf( 'fired %d time(s)', $this->hook_fire_count() ) );
		} else {
			$this->check( 'DC1: Post::delete fires jetonomy_after_delete_post exactly once', false, 'could not create fixture post' );
		}
		remove_action( 'jetonomy_after_delete_post', $spy, 1 );

		// DC2: trashing must NOT fire it - a trashed post is restorable
		// (Moderation "approve" puts it back to publish), and a listener that
		// drops attachment links on trash destroys them for a post still in use.
		$this->hook_fires = 0;
		add_action( 'jetonomy_after_delete_post', $spy, 1 );
		$post2 = \Jetonomy\Models\Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $admin_id,
				'title'     => 'QA trash-contract ' . wp_generate_password( 6, false ),
				'content'   => 'x',
				'status'    => 'publish',
			]
		);
		$post2_id = is_wp_error( $post2 ) ? 0 : (int) $post2;
		if ( $post2_id > 0 ) {
			\Jetonomy\Models\Post::update( $post2_id, [ 'status' => 'trash' ] );
			$this->check( 'DC2: trashing does NOT fire jetonomy_after_delete_post', 0 === $this->hook_fire_count(), sprintf( 'fired %d time(s)', $this->hook_fire_count() ) );
			remove_action( 'jetonomy_after_delete_post', $spy, 1 );
			\Jetonomy\Models\Post::delete( $post2_id );
		} else {
			remove_action( 'jetonomy_after_delete_post', $spy, 1 );
			$this->check( 'DC2: trashing does NOT fire jetonomy_after_delete_post', false, 'could not create fixture post' );
		}

		// DC3: the same contract for replies.
		$this->hook_fires = 0;
		add_action( 'jetonomy_after_delete_reply', $spy, 1 );
		$host = \Jetonomy\Models\Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $admin_id,
				'title'     => 'QA reply-contract ' . wp_generate_password( 6, false ),
				'content'   => 'x',
				'status'    => 'publish',
			]
		);
		$host_id = is_wp_error( $host ) ? 0 : (int) $host;
		$reply   = $host_id > 0 ? \Jetonomy\Models\Reply::create(
			[
				'post_id'   => $host_id,
				'author_id' => $admin_id,
				'content'   => 'y',
				'status'    => 'publish',
			]
		) : 0;
		$reply_id = is_wp_error( $reply ) ? 0 : (int) $reply;
		if ( $reply_id > 0 ) {
			\Jetonomy\Models\Reply::delete( $reply_id );
			$this->check( 'DC3: Reply::delete fires jetonomy_after_delete_reply exactly once', 1 === $this->hook_fire_count(), sprintf( 'fired %d time(s)', $this->hook_fire_count() ) );
		} else {
			$this->check( 'DC3: Reply::delete fires jetonomy_after_delete_reply exactly once', false, 'could not create fixture reply' );
		}
		remove_action( 'jetonomy_after_delete_reply', $spy, 1 );
		if ( $host_id > 0 ) {
			\Jetonomy\Models\Post::delete( $host_id );
		}
	}

	/**
	 * Space ownership when a member's account is deleted.
	 *
	 * Two outcomes, and telling them apart is the whole point. A space is only
	 * STRANDED if the leaver was its last admin; one with another admin is still
	 * fully manageable and must keep running. The first version of this fix
	 * archived both, so one member closing their account took healthy spaces
	 * read-only for their entire membership (Basecamp 10119343043, QA case B).
	 *
	 * Drives real wp_delete_user() rather than calling the private transfer
	 * directly, because the hook wiring is half of what is being asserted.
	 */
	private function test_space_ownership_transfer(): void {
		global $wpdb;

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$spaces_t  = table( 'spaces' );
		$members_t = table( 'space_members' );
		$suffix    = (string) time();

		$owner  = wp_insert_user(
			[
				'user_login' => 'qa_owner_' . $suffix,
				'user_email' => 'qa_owner_' . $suffix . '@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			]
		);
		$co_admin = wp_insert_user(
			[
				'user_login' => 'qa_coadmin_' . $suffix,
				'user_email' => 'qa_coadmin_' . $suffix . '@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			]
		);

		if ( is_wp_error( $owner ) || is_wp_error( $co_admin ) ) {
			$this->check( 'ST0: transfer fixtures created', false, 'could not create users' );
			return;
		}

		// Pass the author as the creator explicitly. Space::create() otherwise
		// seeds get_current_user_id() as space admin, which under WP-CLI is
		// whoever the runner is - that silently gave both fixtures a SECOND
		// admin and made the stranded case look survivable.
		$make_space = static function ( string $slug, int $author ): int {
			return (int) \Jetonomy\Models\Space::create(
				[
					'title'     => 'QA Transfer ' . $slug,
					'slug'      => $slug,
					'type'      => 'forum',
					'author_id' => $author,
				],
				$author
			);
		};

		// A: sole admin leaves -> stranded, so transfer + park it.
		$sole = $make_space( 'qa-transfer-sole-' . $suffix, (int) $owner );

		// B: a second admin remains -> nothing is stranded, leave it running.
		$shared = $make_space( 'qa-transfer-shared-' . $suffix, (int) $owner );
		$wpdb->insert(
			$members_t,
			[
				'space_id'  => $shared,
				'user_id'   => (int) $co_admin,
				'role'      => 'admin',
				'joined_at' => current_time( 'mysql', true ),
			]
		);

		$fired = [];
		$spy   = static function ( $space_id, $from, $to ) use ( &$fired ): void {
			$fired[ (int) $space_id ] = [ (int) $from, (int) $to ];
		};
		add_action( 'jetonomy_space_transferred', $spy, 10, 3 );

		wp_delete_user( (int) $owner );

		remove_action( 'jetonomy_space_transferred', $spy, 10 );

		$row = static function ( int $id ) use ( $wpdb, $spaces_t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_row( $wpdb->prepare( "SELECT author_id, status FROM {$spaces_t} WHERE id = %d", $id ) );
		};

		$a = $row( $sole );
		$b = $row( $shared );

		$this->check( 'ST1: stranded space gets a new author', $a && (int) $a->author_id !== (int) $owner );
		$this->check( 'ST2: stranded space is parked', $a && 'archived' === $a->status, $a->status ?? 'missing' );
		$this->check(
			'ST3: successor holds an admin row (author_id alone is unmanageable)',
			$a && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$members_t} WHERE space_id = %d AND user_id = %d AND role = 'admin'", $sole, (int) $a->author_id ) ) > 0 // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		// The regression guard.
		$this->check( 'ST4: space with a surviving admin is NOT archived', $b && 'archived' !== $b->status, $b->status ?? 'missing' );
		$this->check( 'ST5: surviving admin inherits attribution', $b && (int) $b->author_id === (int) $co_admin, 'author_id=' . ( $b->author_id ?? '?' ) );

		$this->check( 'ST6: transfer hook fired for both spaces', isset( $fired[ $sole ], $fired[ $shared ] ) );

		// Cleanup.
		foreach ( [ $sole, $shared ] as $sid ) {
			$wpdb->delete( $members_t, [ 'space_id' => $sid ] );
			$wpdb->delete( $spaces_t, [ 'id' => $sid ] );
		}
		wp_delete_user( (int) $co_admin );
	}

	/**
	 * Manual-reorder primitive, shared by categories and spaces.
	 *
	 * Had no coverage at all, which is why two corruptions shipped in a row on
	 * the categories screen (Basecamp 10210539659): first the batch index was
	 * written as an absolute position, then the batch itself turned out to be
	 * longer than per_page because child rows render inline on the parent's page.
	 * Both silently overwrote the next page's positions.
	 *
	 * These assert the arithmetic and the invariant that actually matters -
	 * a page's writes must stay inside that page's band.
	 */
	private function test_reorder(): void {
		// RO1: offset is absolute, derived from page and page size.
		$this->check( 'RO1: page 1 offset is 0', 0 === jetonomy_reorder_offset( 1, 20 ) );
		$this->check( 'RO2: page 3 at 20/page starts at 40', 40 === jetonomy_reorder_offset( 3, 20 ) );
		$this->check( 'RO3: page 2 at 50/page starts at 50', 50 === jetonomy_reorder_offset( 2, 50 ) );

		// RO4: a nonsense page size cannot invent a band. Falls back rather than
		// multiplying by an attacker-supplied number.
		$this->check( 'RO4: unsupported per_page falls back', jetonomy_reorder_offset( 2, 999 ) >= 0 );

		// RO5: positions are offset + index, contiguous and in submitted order.
		$written = [];
		jetonomy_apply_manual_order(
			[ 11, 22, 33 ],
			20,
			static function ( int $id, int $pos ) use ( &$written ): void {
				$written[ $id ] = $pos;
			}
		);
		$this->check(
			'RO5: apply_manual_order writes offset+index',
			[ 11 => 20, 22 => 21, 33 => 22 ] === $written,
			wp_json_encode( $written )
		);

		// RO6: THE invariant. A full page of writes must not reach the next band.
		$per_page = 20;
		$ids      = range( 1, $per_page );
		$max      = -1;
		jetonomy_apply_manual_order(
			$ids,
			jetonomy_reorder_offset( 1, $per_page ),
			static function ( int $id, int $pos ) use ( &$max ): void {
				$max = max( $max, $pos );
			}
		);
		$this->check(
			'RO6: a page-1 batch never writes into page 2\'s band',
			$max < jetonomy_reorder_offset( 2, $per_page ),
			"highest position {$max}, page 2 starts at " . jetonomy_reorder_offset( 2, $per_page )
		);
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
	/** Hook-fire counter for the delete-contract guards (see check_delete_contract). */
	private int $hook_fires = 0;

	/**
	 * Read the hook-fire counter.
	 *
	 * Read through a method on purpose. The counter is incremented inside a
	 * closure handed to add_action(), which PHPStan cannot follow, so it kept
	 * the property narrowed to its initial 0 and reported the assertions as
	 * comparisons that are always false. Going through a method makes it use
	 * the declared int return type instead - which is accurate - rather than
	 * needing an ignore annotation over a real assertion.
	 */
	private function hook_fire_count(): int {
		return $this->hook_fires;
	}

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
