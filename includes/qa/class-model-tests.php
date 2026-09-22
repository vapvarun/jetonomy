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

use Jetonomy\Models\Category;
use Jetonomy\Models\Import_Map;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
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

	/**
	 * Count of checks that could not run.
	 *
	 * @var int
	 */
	private int $skipped = 0;

	// ──────────────────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Run all Phase-2 model unit tests.
	 *
	 * @return array{ pass: int, fail: int, skipped: int }
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
			$this->skip( 'SM5: is_member', 'no space' );
			$this->skip( 'SM6: get_role', 'no space' );
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
				$this->skip( 'RE9: is_space_banned', 'no space' );
				$this->skip( 'RE9: is_space_banned false after remove', 'precondition not met' );
			}

			wp_delete_user( $test_uid );
		} else {
			$this->skip( 'RE7: ban/unban', 'test user creation failed' );
			$this->skip( 'RE7: unban check', 'precondition not met' );
			$this->skip( 'RE8: silence/unsilence', 'precondition not met' );
			$this->skip( 'RE8: unsilence check', 'precondition not met' );
			$this->skip( 'RE9: space ban', 'precondition not met' );
			$this->skip( 'RE9: space unban check', 'precondition not met' );
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
		$this->test_category_visibility();
		$this->test_child_space_in_category_listing();
		$this->test_leaderboard_ranking();
		$this->test_reply_count_moderation();
		$this->test_bbpress_import_scope();
		$this->test_media_cleanup_scope();
		$this->test_category_space_count();
		$this->test_import_map();

		$this->check_delete_contract( $admin_id );
		$this->check_shortcode_ref_contract();
		$this->check_settings_round_trip();

		return [ 'pass' => $this->pass, 'fail' => $this->fail, 'skipped' => $this->skipped ];
	}

	/**
	 * SR1-SR4: a shortcode reference that names nothing must SAY so.
	 *
	 * Every shortcode taking a space or category accepts an id or a slug, and
	 * must answer the same question: does this thing exist? The first pass
	 * verified only jetonomy_space_members' slug branch, so a non-existent
	 * NUMERIC id fell through with a truthy value and the owner was shown an
	 * ordinary empty state - indistinguishable from a real empty space, which is
	 * the confusion the notices exist to end. jetonomy_compose_topic was worse:
	 * its check sat inside the 'fixed' branch while the default mode is
	 * 'picker', so a bogus id rendered a complete working compose form
	 * (Basecamp 10266123693).
	 *
	 * Also pins that a slug and the matching numeric id produce the SAME output,
	 * because accepting a slug and then absint()-ing it to 0 silently queried
	 * space 0 and returned nothing.
	 */
	/**
	 * Settings round-trip: what the owner saved is what is stored and used.
	 *
	 * Every check here is a defect that shipped, and they share one shape - the
	 * screen said "saved" while the value was silently changed, dropped, or
	 * never read. None of them is visible to a static gate, and none was
	 * covered, which is why a whole settings audit found them at once.
	 *
	 * @return void
	 */
	private function check_settings_round_trip(): void {
		$admin = new \Jetonomy\Admin\Admin();

		// SV1: a save from a tab that renders no template fields must not wipe
		// the overrides. jetonomy_email_templates is registered in the same
		// settings group as everything else, so options.php calls this
		// sanitizer with nothing on EVERY tab's save - and it used to answer
		// with an empty array.
		$templates_before = get_option( 'jetonomy_email_templates', array() );
		update_option(
			'jetonomy_email_templates',
			array( 'mention' => array( 'subject' => 'SV probe', 'body' => 'SV body' ) )
		);
		$kept = $admin->sanitize_email_templates( null );
		$this->check(
			'SV1: saving another tab keeps the custom email templates',
			isset( $kept['mention']['subject'] ) && 'SV probe' === $kept['mention']['subject']
		);

		// SV2: the widen-check - the Email tab itself must still be able to
		// clear a row, or SV1 could be satisfied by making templates permanent.
		$cleared = $admin->sanitize_email_templates(
			array(
				'_submitted' => '1',
				'mention'    => array( 'subject' => '', 'body' => '' ),
			)
		);
		$this->check( 'SV2: the Email tab can still clear a template', array() === $cleared );
		update_option( 'jetonomy_email_templates', $templates_before );

		// SV3: the empty "no restriction" option must REMOVE who_can_post, not
		// store a value. The admin JS used to coerce '' to 'members', which
		// turned an open community into members-only whenever the owner touched
		// any other field on that tab.
		$spaces_t = table( 'spaces' );
		$space_id = (int) $this->db_insert_probe_space( $spaces_t );
		if ( $space_id > 0 ) {
			Space::update(
				$space_id,
				array( 'settings' => wp_json_encode( Space::merge_settings( $space_id, array( 'who_can_post' => 'members' ) ) ) )
			);
			$merged = Space::merge_settings( $space_id, array( 'who_can_post' => '', 'posts_per_page' => 9 ) );
			$this->check(
				'SV3: the empty post restriction unsets the key instead of storing members',
				! array_key_exists( 'who_can_post', $merged ) && 9 === (int) ( $merged['posts_per_page'] ?? 0 )
			);

			global $wpdb;
			$wpdb->delete( $spaces_t, array( 'id' => $space_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$this->skip( 'SV3: empty post restriction unsets the key', 'probe space insert failed' );
		}

		// SV4: container_width is an ENUM, and sanitize_settings() is what keeps
		// a length out of the option. The emitted CSS is built inside
		// Template_Loader::render(), so the "is it printed as a length" half is
		// a browser check, not this one - what is asserted here is the contract
		// every reader relies on: the stored value is only ever theme / full /
		// custom, with the length living in its own key.
		$sanitized = $admin->sanitize_settings(
			array(
				'accent_color'           => '#0073aa',
				'container_width'        => '1280px',
				'container_width_custom' => '1440',
			)
		);
		$this->check(
			'SV4: container_width stores only the enum, never a length',
			in_array( $sanitized['container_width'] ?? '', array( 'theme', 'full', 'custom' ), true ),
			(string) ( $sanitized['container_width'] ?? '(unset)' )
		);
		$this->check(
			'SV4: the custom length lives in its own key, clamped',
			1440 === (int) ( $sanitized['container_width_custom'] ?? 0 ),
			(string) ( $sanitized['container_width_custom'] ?? '(unset)' )
		);
	}

	/**
	 * Insert a throwaway active space for a settings probe.
	 *
	 * @param string $spaces_t Prefixed spaces table.
	 * @return int Row id, or 0.
	 */
	private function db_insert_probe_space( string $spaces_t ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$spaces_t,
			array(
				'category_id' => 0,
				'parent_id'   => 0,
				'author_id'   => 1,
				'type'        => 'forum',
				'title'       => 'QA Settings Probe',
				'slug'        => 'qa-settings-probe-' . wp_generate_password( 6, false ),
				'visibility'  => 'public',
				'join_policy' => 'open',
				'status'      => 'active',
				'created_at'  => \Jetonomy\now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function check_shortcode_ref_contract(): void {
		global $wpdb;

		$missing = [
			'jetonomy_recent_posts'   => 'space_id',
			'jetonomy_trending_posts' => 'space_id',
			'jetonomy_spaces'         => 'category_id',
			'jetonomy_compose_topic'  => 'space_id',
			'jetonomy_space_members'  => 'space_id',
		];

		$bad = [];
		foreach ( $missing as $tag => $att ) {
			$out = do_shortcode( "[{$tag} {$att}=\"99999\"]" );
			if ( false === strpos( $out, 'jt-shortcode-notice' ) ) {
				$bad[] = $tag;
			}
		}
		$this->check(
			'SR1: a non-existent id is reported, not rendered as an empty state',
			empty( $bad ),
			'silent for: ' . implode( ', ', $bad )
		);

		// SR2: visitors never see the editor guidance.
		$current = get_current_user_id();
		wp_set_current_user( 0 );
		$leaked = [];
		foreach ( $missing as $tag => $att ) {
			if ( '' !== trim( do_shortcode( "[{$tag} {$att}=\"99999\"]" ) ) ) {
				$leaked[] = $tag;
			}
		}
		wp_set_current_user( $current );
		$this->check( 'SR2: the notice is never shown to visitors', empty( $leaked ), 'leaked from: ' . implode( ', ', $leaked ) );

		// SR3/SR4: a slug and its numeric id must resolve identically.
		$spaces_t = $wpdb->prefix . 'jt_spaces';
		$cats_t   = $wpdb->prefix . 'jt_categories';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space = $wpdb->get_row( "SELECT id, slug FROM {$spaces_t} ORDER BY id ASC LIMIT 1", ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cat = $wpdb->get_row( "SELECT id, slug FROM {$cats_t} ORDER BY id ASC LIMIT 1", ARRAY_A );

		if ( is_array( $space ) && ! empty( $space['slug'] ) ) {
			$by_slug = do_shortcode( '[jetonomy_space_members space_id="' . $space['slug'] . '"]' );
			$by_id   = do_shortcode( '[jetonomy_space_members space_id="' . (int) $space['id'] . '"]' );
			$this->check( 'SR3: space slug and numeric id resolve identically', $by_slug === $by_id, 'slug and id produced different output' );
		} else {
			\WP_CLI::log( '    SKIP  SR3: no space on this site (run demo-seed)' );
		}

		if ( is_array( $cat ) && ! empty( $cat['slug'] ) ) {
			$by_slug = do_shortcode( '[jetonomy_spaces category_id="' . $cat['slug'] . '"]' );
			$by_id   = do_shortcode( '[jetonomy_spaces category_id="' . (int) $cat['id'] . '"]' );
			$this->check( 'SR4: category slug and numeric id resolve identically', $by_slug === $by_id, 'slug and id produced different output' );
		} else {
			\WP_CLI::log( '    SKIP  SR4: no category on this site (run demo-seed)' );
		}
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
	/**
	 * Category visibility is ENFORCED, not just stored.
	 *
	 * `jt_categories.visibility` was written by the admin UI from day one and
	 * read by nothing: a `hidden` category was listed on the public directory
	 * and returned by an unauthenticated `GET /categories`, `visibility` field
	 * and all. A space inside it leaked the same way, because a space consulted
	 * only its own column and never its parent's.
	 *
	 * Both halves are asserted here rather than in a browser test because the
	 * defect is in the query, and a template test would pass the moment someone
	 * hid the row in CSS.
	 */
	private function test_category_visibility(): void {
		global $wpdb;

		$slug = 'jt-qa-vis-' . wp_generate_password( 6, false, false );
		$wpdb->insert(
			\Jetonomy\table( 'categories' ),
			[
				'name'       => 'QA Visibility Probe',
				'slug'       => $slug,
				'visibility' => 'hidden',
				'parent_id'  => 0,
				'sort_order' => 0,
				'created_at' => \Jetonomy\now(),
			]
		);
		$cat_id = (int) $wpdb->insert_id;

		$previous_user = get_current_user_id();

		// CV1-CV3: a guest must not reach a hidden category by any read path.
		wp_set_current_user( 0 );
		$guest_slugs = array_column( Category::list_top_level(), 'slug' );
		$this->check( 'CV1: guest listing omits a hidden category', ! in_array( $slug, $guest_slugs, true ) );
		$this->check( 'CV2: guest cannot resolve a hidden category by slug', null === Category::find_by_slug( $slug ) );

		[ $guest_where ] = Category::listing_visibility_sql( 0 );
		$this->check( 'CV3: guest predicate restricts to public', "visibility = 'public'" === $guest_where, $guest_where );

		// CV4: the owner keeps full sight - a filter that blinds the admin
		// screen would "pass" CV1-CV3 while breaking management.
		wp_set_current_user( 1 );
		$admin_slugs = array_column( Category::list_top_level(), 'slug' );
		$this->check( 'CV4: a category manager still sees a hidden category', in_array( $slug, $admin_slugs, true ) );

		// CV5: effective visibility - a PUBLIC space inside a HIDDEN category
		// is concealed from a guest, resolved at read time from the parent.
		$spaces_table = \Jetonomy\table( 'spaces' );
		$wpdb->insert(
			$spaces_table,
			[
				'category_id' => $cat_id,
				'parent_id'   => 0,
				'author_id'   => 1,
				'type'        => 'forum',
				'title'       => 'QA Visibility Probe Space',
				'slug'        => $slug . '-space',
				'visibility'  => 'public',
				'status'      => 'active',
				'created_at'  => \Jetonomy\now(),
			]
		);
		$space_id = (int) $wpdb->insert_id;

		wp_set_current_user( 0 );
		$guest_space_ids = array_map( 'intval', array_column( Space::list_by_category( $cat_id, 0 ), 'id' ) );
		$this->check(
			'CV5: a public space in a hidden category is concealed from a guest',
			! in_array( $space_id, $guest_space_ids, true )
		);

		$owner_space_ids = array_map( 'intval', array_column( Space::list_by_category( $cat_id, 1 ), 'id' ) );
		$this->check(
			'CV6: the owner still sees that space',
			in_array( $space_id, $owner_space_ids, true )
		);

		// CV7-CV12: the ACCESS half. CV1-CV6 only proved the space and category
		// stay out of LISTINGS, which is why the leak survived a release: every
		// read path answered from the space's own `visibility` field and never
		// looked at the parent, so a stranger holding the URL or the id got the
		// space, its topics and its JSON-LD. Each check below is one surface
		// that was serving that content.
		$posts_table = \Jetonomy\table( 'posts' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$posts_table,
			[
				'space_id'   => $space_id,
				'author_id'  => 1,
				'title'      => 'QA Visibility Probe Topic',
				'slug'       => $slug . '-topic',
				'content'    => 'probe',
				'status'     => 'publish',
				'created_at' => \Jetonomy\now(),
			]
		);
		$post_id  = (int) $wpdb->insert_id;
		$probe_post = (object) [
			'id'         => $post_id,
			'space_id'   => $space_id,
			'author_id'  => 1,
			'status'     => 'publish',
			'is_private' => 0,
		];
		$probe_space = (object) [
			'id'          => $space_id,
			'category_id' => $cat_id,
			'visibility'  => 'public',
		];

		wp_set_current_user( 0 );
		$this->check(
			'CV7: a guest cannot read a space inside a hidden category',
			! Permission_Engine::can( 0, 'read', $space_id )
		);
		$this->check(
			'CV8: the direct URL conceals it from a guest (404, not a gate page)',
			Space::concealed_from_viewer( $probe_space, 0 )
		);
		$this->check(
			'CV9: the REST read gate agrees with the template gate',
			! Space::readable_by_viewer( $probe_space, 0 )
		);
		$this->check(
			'CV10: its topics are unreadable by a guest on every surface',
			! Permission_Engine::can_read_post( 0, $probe_post )
		);
		$this->check(
			'CV11: find_visible withholds a hidden category from a guest',
			null === Category::find_visible( $cat_id, 0 )
		);

		// CV13-CV15: membership overrides the parent, the same way it does for
		// a hidden SPACE. QA's read of one word has to hold: "hidden" means
		// only members can find this, so tidying a category must not take a
		// member's own space away from them - and a card that shows in a
		// listing must open, which is why the listing and the read side are
		// asserted together on the same row.
		$member_id = wp_insert_user(
			[
				'user_login' => 'jt_qa_cv_member_' . $post_id,
				'user_pass'  => wp_generate_password( 16 ),
				'user_email' => 'jt-qa-cv-' . $post_id . '@test.local',
				'role'       => 'subscriber',
			]
		);
		$member_id = ( $member_id && ! is_wp_error( $member_id ) ) ? (int) $member_id : 0;

		if ( $member_id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$spaces_table,
				[
					'category_id' => $cat_id,
					'parent_id'   => 0,
					'author_id'   => 1,
					'type'        => 'forum',
					'title'       => 'QA Visibility Probe Private Space',
					'slug'        => $slug . '-private',
					'visibility'  => 'private',
					'status'      => 'active',
					'created_at'  => \Jetonomy\now(),
				]
			);
			$priv_space_id = (int) $wpdb->insert_id;
			SpaceMember::add( $priv_space_id, $member_id, 'member' );

			wp_set_current_user( $member_id );
			$member_space_ids = array_map( 'intval', array_column( Space::list_by_category( $cat_id, $member_id ), 'id' ) );
			$this->check(
				'CV13: a member keeps their own space listed inside a hidden category',
				in_array( $priv_space_id, $member_space_ids, true )
			);
			$this->check(
				'CV14: and can read it - a card that lists must open',
				Permission_Engine::can( $member_id, 'read', $priv_space_id )
			);
			$this->check(
				'CV15: but gains nothing else in that category',
				! in_array( $space_id, $member_space_ids, true )
					&& ! Permission_Engine::can( $member_id, 'read', $space_id )
			);

			$wpdb->delete( \Jetonomy\table( 'space_members' ), [ 'space_id' => $priv_space_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $spaces_table, [ 'id' => $priv_space_id ] );
			wp_delete_user( $member_id );
		} else {
			$this->skip( 'CV13: member keeps their space', 'test user creation failed' );
			$this->skip( 'CV14: member can read it', 'precondition not met' );
			$this->skip( 'CV15: member gains nothing else', 'precondition not met' );
		}

		// The widen-check that stops all of CV7-CV11 being satisfied by simply
		// denying everyone.
		wp_set_current_user( 1 );
		$this->check(
			'CV12: the owner still reads the space and its topics',
			Permission_Engine::can( 1, 'read', $space_id )
				&& Space::readable_by_viewer( $probe_space, 1 )
				&& Permission_Engine::can_read_post( 1, $probe_post )
				&& null !== Category::find_visible( $cat_id, 1 )
		);

		wp_set_current_user( $previous_user );

		$wpdb->delete( $posts_table, [ 'id' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $spaces_table, [ 'id' => $space_id ] );
		$wpdb->delete( \Jetonomy\table( 'categories' ), [ 'id' => $cat_id ] );
	}

	/**
	 * A child space is listed under the category it was assigned.
	 *
	 * The category listing used to carry `parent_id = 0`, so a space with a
	 * parent had its `category_id` written, indexed and then ignored. Nothing
	 * nested those children either, so an imported sub-forum was reachable
	 * from nowhere in the directory - the shape two customers reported after
	 * migrating.
	 *
	 * The count is asserted alongside the list because they are separate
	 * queries that carried the same clause: fixing one and not the other gives
	 * pagination that disagrees with its own rows.
	 */
	private function test_child_space_in_category_listing(): void {
		global $wpdb;

		$suffix = wp_generate_password( 6, false, false );
		$cats   = \Jetonomy\table( 'categories' );
		$spaces = \Jetonomy\table( 'spaces' );

		$wpdb->insert(
			$cats,
			[
				'name'       => 'QA Child Listing Probe',
				'slug'       => 'jt-qa-child-' . $suffix,
				'visibility' => 'public',
				'parent_id'  => 0,
				'sort_order' => 0,
				'created_at' => \Jetonomy\now(),
			]
		);
		$cat_id = (int) $wpdb->insert_id;

		$make_space = static function ( int $parent_id, int $category_id, string $slug ) use ( $wpdb, $spaces ): int {
			$wpdb->insert(
				$spaces,
				[
					'category_id' => $category_id,
					'parent_id'   => $parent_id,
					'author_id'   => 1,
					'type'        => 'forum',
					'title'       => 'QA Child Listing ' . $slug,
					'slug'        => $slug,
					'visibility'  => 'public',
					'status'      => 'active',
					'created_at'  => \Jetonomy\now(),
				]
			);
			return (int) $wpdb->insert_id;
		};

		$parent_id = $make_space( 0, $cat_id, 'jt-qa-parent-' . $suffix );
		$child_id  = $make_space( $parent_id, $cat_id, 'jt-qa-kid-' . $suffix );

		$previous_user = get_current_user_id();
		wp_set_current_user( 0 );

		$ids = array_map( 'intval', array_column( Space::list_by_category( $cat_id, 0 ), 'id' ) );
		$this->check( 'SS1: a child space appears under its assigned category', in_array( $child_id, $ids, true ) );
		$this->check( 'SS2: its top-level parent is still listed', in_array( $parent_id, $ids, true ) );

		$count = (int) Space::count_by_category( $cat_id, 0 );
		$this->check(
			'SS3: count_by_category agrees with the rows it paginates',
			$count === count( $ids ),
			"count={$count}, rows=" . count( $ids )
		);

		// SS4: listed once. The tree groups by category, so a duplicate would
		// show up as the same id twice in the same bucket.
		$tree   = Space::visible_by_category( 0 );
		$bucket = array_map( 'intval', array_column( $tree[ $cat_id ] ?? [], 'id' ) );
		$this->check(
			'SS4: the child is listed exactly once, not also nested',
			1 === count( array_keys( $bucket, $child_id, true ) ),
			wp_json_encode( $bucket )
		);

		wp_set_current_user( $previous_user );

		$wpdb->delete( $spaces, [ 'id' => $child_id ] );
		$wpdb->delete( $spaces, [ 'id' => $parent_id ] );
		$wpdb->delete( $cats, [ 'id' => $cat_id ] );
		self::bust_space_tree();
	}

	/**
	 * Drop the cached category tree so a probe's rows never outlive the test.
	 */
	private static function bust_space_tree(): void {
		if ( method_exists( Space::class, 'bump_tree_generation' ) ) {
			$m = new \ReflectionMethod( Space::class, 'bump_tree_generation' );
			$m->setAccessible( true );
			$m->invoke( null );
		}
	}

	/**
	 * The leaderboard ranks by reputation, and only real members hold a rank.
	 *
	 * Two defects sat in the same view. Rows were numbered by array index
	 * while the "Your rank" badge used competition ranking, so tied members
	 * were numbered 10 and 11 while both were told "#10". And a profile whose
	 * WP user no longer existed was fetched, failed to render, and was dropped
	 * AFTER spending its position - leaving a visible hole (... 17, 18, 20)
	 * and shifting everyone below it.
	 */
	private function test_leaderboard_ranking(): void {
		$rows = UserProfile::list_for_leaderboard( 'all', 100, 0 );

		// LB1: no profile without a WP user may hold a rank. This is asserted
		// on the QUERY, not the view — filtering in the view would leave the
		// total and rank_for_user() counting a different population.
		$orphans = array_filter( $rows, static fn( $r ) => ! get_user_by( 'ID', (int) $r->user_id ) );
		$this->check(
			'LB1: leaderboard rows all resolve to a real member',
			0 === count( $orphans ),
			count( $orphans ) . ' orphan profile(s)'
		);

		// LB2: the total counts the same population the page lists.
		$total = UserProfile::count_for_leaderboard( 'all' );
		$this->check(
			'LB2: count_for_leaderboard matches the listed population',
			$total === count( $rows ),
			"count={$total}, rows=" . count( $rows )
		);

		// LB3: ties share a rank — the property the view must now honour.
		// Derived from the same ordered page the view renders.
		$ok       = true;
		$detail   = '';
		$prev_rep = null;
		$expected = 0;
		foreach ( array_values( $rows ) as $i => $row ) {
			$rep = (int) $row->reputation;
			if ( null === $prev_rep || $rep < $prev_rep ) {
				$expected = $i + 1;
			}
			$prev_rep = $rep;

			$actual = UserProfile::rank_for_user( (int) $row->user_id, 'all' );
			if ( $actual !== $expected ) {
				$ok     = false;
				$detail = "user {$row->user_id} rep {$rep}: rank_for_user={$actual}, position-derived={$expected}";
				break;
			}
		}
		$this->check( 'LB3: competition rank agrees with reputation order (ties share)', $ok, $detail );

		// LB4: ranks never skip except across a shared rank — the signature of
		// the old hole. After N members share a rank, the next is rank+N.
		$ranks    = array_map( static fn( $r ) => UserProfile::rank_for_user( (int) $r->user_id, 'all' ), array_values( $rows ) );
		$holes    = 0;
		$run_rank = null;
		$run_len  = 0;
		foreach ( $ranks as $r ) {
			if ( $r === $run_rank ) {
				++$run_len;
				continue;
			}
			if ( null !== $run_rank && $r !== $run_rank + $run_len ) {
				++$holes;
			}
			$run_rank = $r;
			$run_len  = 1;
		}
		$this->check( 'LB4: no unexplained gap in the rank sequence', 0 === $holes, "{$holes} gap(s)" );
	}

	/**
	 * reply_count counts PUBLISHED replies, through every status path.
	 *
	 * Reply::create() used to increment unconditionally while the counter -
	 * and Recount, which defines it - mean published only. update() already
	 * applies its own +1/-1 when a reply crosses the publish boundary, so a
	 * reply that did not start published was counted twice: held for approval
	 * gave counter 1 against 0 published, and approving it gave 2 against 1.
	 * Every moderated reply permanently inflated its thread by one.
	 */
	private function test_reply_count_moderation(): void {
		global $wpdb;

		$post_id = (int) $wpdb->get_var( 'SELECT id FROM ' . \Jetonomy\table( 'posts' ) . " WHERE status = 'publish' ORDER BY id DESC LIMIT 1" );
		if ( ! $post_id ) {
			$this->check( 'RC0: a published post exists to reply to', false, 'no post available' );
			return;
		}

		$posts_table   = \Jetonomy\table( 'posts' );
		$replies_table = \Jetonomy\table( 'replies' );

		$counter = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT reply_count FROM {$posts_table} WHERE id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actual  = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$replies_table} WHERE post_id = %d AND status = 'publish'", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$restore = $counter();

		// RC1: a reply held for approval is not yet a published reply.
		$held = Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => 1,
				'content'   => 'QA moderation probe',
				'status'    => 'pending',
			]
		);
		$this->check(
			'RC1: a pending reply does not raise reply_count',
			$counter() === $actual(),
			'counter ' . $counter() . ' vs published ' . $actual()
		);

		// RC2: approving it counts it exactly once, not a second time.
		Reply::update( $held, [ 'status' => 'publish' ] );
		$this->check(
			'RC2: approving a held reply counts it exactly once',
			$counter() === $actual(),
			'counter ' . $counter() . ' vs published ' . $actual()
		);

		// RC3: and the ordinary path is unchanged.
		$normal = Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => 1,
				'content'   => 'QA direct probe',
			]
		);
		$this->check( 'RC3: a directly published reply still counts', $counter() === $actual() );

		Reply::delete( $normal );
		Reply::delete( $held );
		$this->check( 'RC4: deleting replies leaves the counter correct', $counter() === $actual() );

		$wpdb->update( $posts_table, [ 'reply_count' => $restore ], [ 'id' => $post_id ] );
	}

	/**
	 * The bbPress importer counts what it will actually take.
	 *
	 * Every query hard-filtered `post_status = 'publish'`, which dropped closed
	 * topics and private/hidden forums - and the pre-import estimate used the
	 * SAME filter, so the final tally matched the estimate exactly and the
	 * shortfall was invisible from both ends. That symmetry is the thing worth
	 * guarding: an estimate that disagrees with the import is a visible bug, an
	 * estimate that agrees with a lossy import is a silent one.
	 *
	 * Skips when no bbPress content is present, so this is meaningful on a
	 * migration site and harmless everywhere else.
	 */
	private function test_bbpress_import_scope(): void {
		global $wpdb;

		$importer = new \Jetonomy\Import\BBPress_Importer();
		if ( ! $importer->is_source_available() ) {
			$this->skip( 'BB1: bbPress import scope', 'no bbPress content on this site' );
			return;
		}

		$stats = $importer->get_source_stats();

		// BB1: a closed topic is a topic. bbPress stores "closed" as the
		// post_status, so a publish-only filter drops it.
		$closed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'closed'" );
		$topics = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status IN ('publish','closed')" );
		$this->check(
			'BB1: the topic estimate includes closed topics',
			(int) $stats['topics'] === $topics,
			"estimate {$stats['topics']}, publish+closed {$topics} (closed: {$closed})"
		);

		// BB2: private and hidden forums are forums.
		$forums = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status IN ('publish','private','hidden')" );
		$this->check(
			'BB2: the forum estimate includes private and hidden forums',
			(int) $stats['forums'] === $forums,
			"estimate {$stats['forums']}, importable {$forums}"
		);

		// BB3: the headline total is the sum of the parts the owner is shown -
		// they drifted apart before because each was computed independently.
		$this->check(
			'BB3: total agrees with the per-type estimates',
			$importer->get_total_count() === array_sum( $stats ),
			$importer->get_total_count() . ' vs ' . array_sum( $stats )
		);
	}

	/**
	 * The media sweep deletes only files we recorded as ours, and not on the
	 * first run.
	 *
	 * MD3 is the one that matters. An earlier version of this sweep was scoped
	 * to META_FLAG, which the backfill also applies to any subscriber-authored
	 * attachment - including another forum plugin's - and it force-deleted
	 * wpForo's files mid-migration, destroying the content the import existed
	 * to rescue. If MD3 ever fails, that incident is back.
	 */
	private function test_media_cleanup_scope(): void {
		global $wpdb;

		$previous = get_option( 'jetonomy_media_cleanup_report' );
		delete_option( 'jetonomy_media_cleanup_report' );

		$make = static function ( string $title, array $meta, int $days ): int {
			$when = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$id   = wp_insert_post(
				[
					'post_type'      => 'attachment',
					'post_title'     => $title,
					'post_status'    => 'inherit',
					'post_author'    => 1,
					'post_mime_type' => 'image/png',
					'post_date'      => $when,
					'post_date_gmt'  => $when,
				]
			);
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
			return (int) $id;
		};

		$ours_old   = $make( 'QA media ours old', [ \Jetonomy\Media_Library::META_ORIGIN => 'upload' ], 5 );
		$ours_fresh = $make( 'QA media ours fresh', [ \Jetonomy\Media_Library::META_ORIGIN => 'upload' ], 0 );
		// Flagged by the backfill but NOT recorded as ours - i.e. somebody
		// else's file. The shape the old sweep destroyed.
		$foreign = $make( 'QA media foreign', [ \Jetonomy\Media_Library::META_FLAG => '1' ], 30 );

		/*
		 * MD5-MD7: the sweep must not delete a file that is IN USE.
		 *
		 * The first version defined "in use" as "has a jt_attachments link
		 * row", and the only free caller of Attachment::link() is the importer
		 * - so every ordinary composer upload looked abandoned. QA reproduced
		 * it deleting an image inline in a published reply. These three cover
		 * each way an upload is actually referenced; if any fails, live member
		 * content is being destroyed.
		 */
		$used = array();
		$mk_used = static function ( string $title ) use ( $make, &$used ) {
			$id = $make( $title, array( \Jetonomy\Media_Library::META_ORIGIN => 'upload' ), 5 );
			update_post_meta( $id, '_wp_attached_file', '2026/09/' . sanitize_title( $title ) . '.png' );
			$used[] = $id;
			return array( $id, (string) get_post_meta( $id, '_wp_attached_file', true ) );
		};

		[ $inline_id, $inline_file ] = $mk_used( 'QA media inline in reply' );
		$reply_id                    = Reply::create(
			array(
				'post_id'   => (int) $wpdb->get_var( 'SELECT id FROM ' . \Jetonomy\table( 'posts' ) . " WHERE status = 'publish' ORDER BY id DESC LIMIT 1" ),
				'author_id' => 1,
				'content'   => '<img src="' . esc_url( content_url( '/uploads/' . $inline_file ) ) . '" />',
			)
		);

		[ $avatar_id, $avatar_file ] = $mk_used( 'QA media as avatar' );
		$profiles                    = \Jetonomy\table( 'user_profiles' );
		$prev_avatar                 = $wpdb->get_var( "SELECT avatar_url FROM {$profiles} WHERE user_id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$profiles} SET avatar_url = %s WHERE user_id = 1", content_url( '/uploads/' . $avatar_file ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		[ $cover_id, $cover_file ] = $mk_used( 'QA media as space cover' );
		$spaces_t                  = \Jetonomy\table( 'spaces' );
		$cover_space               = (int) $wpdb->get_var( "SELECT id FROM {$spaces_t} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev_cover                = $wpdb->get_var( $wpdb->prepare( "SELECT cover_image FROM {$spaces_t} WHERE id = %d", $cover_space ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$spaces_t} SET cover_image = %s WHERE id = %d", content_url( '/uploads/' . $cover_file ), $cover_space ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$first = \Jetonomy\Media_Library::cleanup_abandoned_uploads();
		$this->check(
			'MD1: the first sweep on a site reports and deletes nothing',
			0 === $first['deleted'] && $first['reported'] >= 1,
			wp_json_encode( $first )
		);
		$this->check( 'MD1b: and the eligible upload is still there', (bool) get_post( $ours_old ) );

		// MD1c: the scheduled hook actually reaches the sweep. A job can be
		// scheduled and still do nothing if its callback was never registered -
		// that exact shape was live in Pro, where the GC ran daily against a
		// table lookup that silently matched nothing.
		$this->check(
			'MD1c: jetonomy_cleanup_media is wired to a callback',
			has_action( 'jetonomy_cleanup_media' ) !== false
		);

		$second = \Jetonomy\Media_Library::cleanup_abandoned_uploads();
		$this->check( 'MD2: the next sweep removes an abandoned upload of ours', ! get_post( $ours_old ), wp_json_encode( $second ) );
		$this->check( 'MD3: a file that is NOT ours is never deleted', (bool) get_post( $foreign ) );
		$this->check( 'MD4: an upload inside the 24h grace is kept', (bool) get_post( $ours_fresh ) );

		// The three in-use shapes. Any failure here is live content destroyed.
		$this->check( 'MD5: an image inline in a published reply is never deleted', (bool) get_post( $inline_id ) );
		$this->check( 'MD6: a member avatar is never deleted', (bool) get_post( $avatar_id ) );
		$this->check( 'MD7: a space cover image is never deleted', (bool) get_post( $cover_id ) );

		// Restore the rows the in-use fixtures borrowed.
		Reply::delete( $reply_id );
		$wpdb->query( $wpdb->prepare( "UPDATE {$profiles} SET avatar_url = %s WHERE user_id = 1", $prev_avatar ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$spaces_t} SET cover_image = %s WHERE id = %d", $prev_cover, $cover_space ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $used as $id ) {
			if ( get_post( $id ) ) {
				wp_delete_post( $id, true );
			}
		}

		foreach ( [ $ours_old, $ours_fresh, $foreign ] as $id ) {
			if ( get_post( $id ) ) {
				wp_delete_post( $id, true );
			}
		}

		if ( false === $previous ) {
			delete_option( 'jetonomy_media_cleanup_report' );
		} else {
			update_option( 'jetonomy_media_cleanup_report', $previous, false );
		}
	}

	/**
	 * jt_categories.space_count survives a space being moved or archived.
	 *
	 * Only create() incremented it and only purge decremented it, so a space
	 * moved between categories left the old count too high and the new one too
	 * low, and archiving never decremented at all - while Recount counts active
	 * spaces only. `wp jetonomy recount` repaired it and the next move broke it
	 * again, which is the signature of a missing write-path adjustment.
	 *
	 * Asserted as "stored equals actual" after each transition rather than by
	 * expected numbers, so the test stays true whatever the fixture holds.
	 */
	private function test_category_space_count(): void {
		global $wpdb;

		$cats   = \Jetonomy\table( 'categories' );
		$spaces = \Jetonomy\table( 'spaces' );
		$suffix = wp_generate_password( 6, false, false );

		$mk_cat = static function ( string $name ) use ( $wpdb, $cats ) {
			$wpdb->insert(
				$cats,
				array(
					'name'       => $name,
					'slug'       => sanitize_title( $name ),
					'visibility' => 'public',
					'parent_id'  => 0,
					'sort_order' => 0,
					'created_at' => \Jetonomy\now(),
				)
			);
			return (int) $wpdb->insert_id;
		};

		$cat_a = $mk_cat( 'QA Count A ' . $suffix );
		$cat_b = $mk_cat( 'QA Count B ' . $suffix );

		$space_id = (int) Space::create(
			array(
				'category_id' => $cat_a,
				'parent_id'   => 0,
				'author_id'   => 1,
				'type'        => 'forum',
				'title'       => 'QA Count Space ' . $suffix,
				'slug'        => 'qa-count-space-' . $suffix,
				'visibility'  => 'public',
				'status'       => 'active',
			)
		);

		$stored = static fn( int $c ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_count FROM {$cats} WHERE id = %d", $c ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actual = static fn( int $c ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$spaces} WHERE category_id = %d AND status = 'active'", $c ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exact  = static fn(): bool => $stored( $cat_a ) === $actual( $cat_a ) && $stored( $cat_b ) === $actual( $cat_b );
		$detail = static fn(): string => "A {$stored( $cat_a )}/{$actual( $cat_a )}, B {$stored( $cat_b )}/{$actual( $cat_b )}";

		$this->check( 'SC1: creating a space counts it', $exact(), $detail() );

		Space::update( $space_id, array( 'category_id' => $cat_b ) );
		$this->check( 'SC2: moving a space moves the count with it', $exact(), $detail() );

		Space::update( $space_id, array( 'status' => 'archived' ) );
		$this->check( 'SC3: archiving a space decrements its category', $exact(), $detail() );

		Space::update( $space_id, array( 'category_id' => $cat_a, 'status' => 'active' ) );
		$this->check( 'SC4: a move and a restore in one call stay exact', $exact(), $detail() );

		$wpdb->delete( $spaces, array( 'id' => $space_id ) );
		$wpdb->delete( $cats, array( 'id' => $cat_a ) );
		$wpdb->delete( $cats, array( 'id' => $cat_b ) );
	}

	/**
	 * An importer can recognise its OWN rows, and only its own.
	 *
	 * This is the mechanism that replaced slug matching, and the reason matters
	 * more than the mechanics: a bbPress forum called "general" matches an
	 * owner's own existing "general" space, so a slug-based re-run adopted it
	 * and poured the source forum's topics into the owner's space - skipping
	 * the visibility mapping on the way, so private content could land in a
	 * public space.
	 *
	 * IM4 is the one that would catch a regression to slug matching: identity
	 * must not be shared with a row this importer never created.
	 */
	private function test_import_map(): void {
		global $wpdb;

		$table = \Jetonomy\table( 'import_map' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			// FAIL, not skip. This skipped once and the suite reported all-green
			// on a site where the 2.0.0 migration had never run - so a release
			// blocker (importers silently duplicating everything on a re-run,
			// because they had no identity table) was invisible to the gate that
			// exists to catch it. A skip is for a condition the site legitimately
			// may not meet; a table this plugin version is supposed to have
			// created is not one of those.
			$this->check(
				'IM0: jt_import_map exists (migration ran)',
				false,
				'table missing - Migration_2_0_0 has not run; every importer will duplicate on a re-run'
			);
			return;
		}

		$this->check( 'IM0: jt_import_map exists (migration ran)', true );

		$source = 'qa-' . wp_generate_password( 6, false, false );

		Import_Map::record( $source, 'space', 4242, 11 );
		$this->check( 'IM1: a recorded source row resolves to its Jetonomy row', 11 === Import_Map::find( $source, 'space', 4242 ) );
		$this->check( 'IM2: an unrecorded source row resolves to nothing', 0 === Import_Map::find( $source, 'space', 9999 ) );

		// IM3: a mapping whose target has been deleted must not make the
		// importer skip that content forever - an owner who deletes an
		// imported space and re-imports should get it back.
		Import_Map::record( $source, 'space', 4343, 99999999 );
		$this->check( 'IM3: a mapping pointing at a deleted row self-heals', 0 === Import_Map::find( $source, 'space', 4343 ) );

		// IM4: identity is per source AND per type. Another importer's row, or
		// the same id under a different type, is not ours.
		Import_Map::record( $source, 'post', 4242, 1 );
		$this->check(
			'IM4: identity is scoped to source and object type',
			0 === Import_Map::find( 'qa-other-source', 'space', 4242 )
			&& 11 === Import_Map::find( $source, 'space', 4242 ),
			'a different source or type must not resolve to our row'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'source' => $source ) );
	}

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

	/**
	 * Record a check that could NOT run.
	 *
	 * A skip used to be logged as `check( '... (skipped)', true )`, which made
	 * it indistinguishable from a real assertion in the totals. On a box where
	 * fixtures fail to build, dozens of those turn into "passes" and the suite
	 * reports green while proving nothing - the failure mode this whole audit
	 * exists to remove. Skips are counted separately and never inflate the
	 * pass count.
	 *
	 * @param string $label  What did not run.
	 * @param string $reason Why not.
	 */
	private function skip( string $label, string $reason = '' ): void {
		$msg = "    SKIP  {$label}";
		if ( $reason ) {
			$msg .= " — {$reason}";
		}
		\WP_CLI::log( $msg );
		++$this->skipped;
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
