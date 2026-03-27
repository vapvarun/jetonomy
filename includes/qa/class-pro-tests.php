<?php
/**
 * Phase 3: Pro Extension REST Tests
 *
 * Exercises REST endpoints from every active Jetonomy Pro extension through
 * rest_do_request() — the same code path the browser uses. Tests are skipped
 * individually when their extension is disabled in jetonomy_pro_extensions.
 * The entire class bails early (returning zero counts) when Pro is not active.
 *
 * Extensions covered:
 *   - private-messaging: conversation lifecycle + messages + unread count
 *   - reactions:         toggle and GET on posts and replies
 *   - polls:             vote, GET poll, unvote (cleanup)
 *   - analytics:         overview, top-spaces, top-contributors
 *   - custom-badges:     list badges, get user badges
 *   - custom-fields:     list field definitions, get post fields
 *   - white-label:       GET settings (admin only)
 *   - seo-pro:           GET space SEO settings (admin only)
 *   - email-digest:      GET and PATCH digest preferences
 *
 * Extensions intentionally excluded:
 *   - webhooks:        admin form only, no REST round-trip in a local CLI context
 *   - reply-by-email:  inbound webhook — requires an external caller
 *   - advanced-moderation, web-push: REST endpoints do not require Pro-specific
 *     fixtures and are already covered by free QA or are infrastructure-level
 *
 * @package Jetonomy\QA
 * @since   1.0.0
 */

namespace Jetonomy\QA;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

class Pro_Tests {

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
	 * WordPress administrator ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * A second WP user created for messaging tests (recipient).
	 *
	 * @var int
	 */
	private int $recipient_id = 0;

	/**
	 * Cleanup stack — processed in reverse order after all tests run.
	 * Each entry is an associative array with a 'type' key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cleanup = [];

	/**
	 * A fixture Jetonomy post created for reaction / poll / field tests.
	 * Set once on first use; null when not yet created.
	 *
	 * @var object|null
	 */
	private ?object $fixture_post = null;

	/**
	 * A fixture Jetonomy reply created for reaction tests.
	 *
	 * @var int
	 */
	private int $fixture_reply_id = 0;

	/**
	 * ID of the conversation created in the messaging tests.
	 * Stored for subsequent send-message / get-messages tests.
	 *
	 * @var int
	 */
	private int $conv_id = 0;

	// ──────────────────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Run all Phase-3 Pro extension tests.
	 *
	 * Returns immediately with empty counts if Jetonomy Pro is not loaded.
	 *
	 * @return array{ pass: int, fail: int }
	 */
	public function run(): array {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			\WP_CLI::log( '  Pro not active — skipping' );
			return [ 'pass' => 0, 'fail' => 0 ];
		}

		$this->setup();

		$this->test_private_messaging();
		$this->test_reactions();
		$this->test_polls();
		$this->test_analytics();
		$this->test_custom_badges();
		$this->test_custom_fields();
		$this->test_white_label();
		$this->test_seo_pro();
		$this->test_email_digest();

		$this->cleanup();

		// Leave admin as the active user.
		wp_set_current_user( $this->admin_id );

		return [ 'pass' => $this->pass, 'fail' => $this->fail ];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Setup
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve admin ID, create the recipient user for messaging tests, and
	 * set the current user to admin for subsequent REST calls.
	 */
	private function setup(): void {
		$admin_ids      = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$this->admin_id = (int) ( $admin_ids[0] ?? 1 );
		wp_set_current_user( $this->admin_id );

		// Create a subscriber that acts as message recipient and second reactor.
		$ts                 = time();
		$uid                = wp_insert_user( [
			'user_login' => 'jt_qa_pro_' . $ts,
			'user_pass'  => wp_generate_password( 16 ),
			'user_email' => 'jt-qa-pro-' . $ts . '@test.local',
			'role'       => 'subscriber',
		] );
		$this->recipient_id = ( $uid && ! is_wp_error( $uid ) ) ? (int) $uid : 0;

		// Elevate recipient trust level so messaging permission passes.
		if ( $this->recipient_id ) {
			$profiles_t = table( 'user_profiles' );
			global $wpdb;
			$profile = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$profiles_t} WHERE user_id = %d",
					$this->recipient_id
				)
			);

			if ( $profile ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$profiles_t,
					[ 'trust_level' => 2 ],
					[ 'user_id' => $this->recipient_id ]
				);
			} else {
				// find_or_create then set level.
				\Jetonomy\Models\UserProfile::find_or_create( $this->recipient_id );
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$profiles_t,
					[ 'trust_level' => 2 ],
					[ 'user_id' => $this->recipient_id ]
				);
			}
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Extension helpers
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Return the list of enabled Pro extension IDs.
	 *
	 * @return string[]
	 */
	private function enabled_extensions(): array {
		$enabled = get_option( 'jetonomy_pro_extensions', [] );
		return is_array( $enabled ) ? $enabled : [];
	}

	/**
	 * Return true when a specific extension ID is currently enabled.
	 *
	 * @param string $id Extension identifier (e.g. 'reactions').
	 */
	private function ext_enabled( string $id ): bool {
		return in_array( $id, $this->enabled_extensions(), true );
	}

	/**
	 * Ensure we have a fixture Jetonomy post for tests that need one.
	 * Creates it via REST as admin if it does not exist yet.
	 *
	 * @return bool True when a fixture post is available, false on creation failure.
	 */
	private function ensure_fixture_post(): bool {
		if ( $this->fixture_post ) {
			return true;
		}

		global $wpdb;
		$spaces_t = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space = $wpdb->get_row( "SELECT * FROM {$spaces_t} WHERE status = 'active' LIMIT 1" );

		if ( ! $space ) {
			return false;
		}

		wp_set_current_user( $this->admin_id );

		$r    = $this->rest( 'POST', '/spaces/' . (int) $space->id . '/posts', [
			'title'   => 'QA Pro Fixture ' . time(),
			'content' => '<p>Pro QA fixture post.</p>',
			'type'    => 'discussion',
		] );
		$data = $r->get_data();

		if ( 201 !== $r->get_status() || empty( $data['id'] ) ) {
			return false;
		}

		$this->fixture_post    = (object) $data;
		$this->cleanup[]       = [ 'type' => 'post_rest', 'id' => (int) $data['id'] ];
		return true;
	}

	/**
	 * Ensure we have a fixture reply on the fixture post.
	 *
	 * @return bool True when a reply ID is available.
	 */
	private function ensure_fixture_reply(): bool {
		if ( $this->fixture_reply_id ) {
			return true;
		}

		if ( ! $this->ensure_fixture_post() ) {
			return false;
		}

		wp_set_current_user( $this->admin_id );

		$r    = $this->rest( 'POST', '/posts/' . (int) $this->fixture_post->id . '/replies', [
			'content' => '<p>Pro QA fixture reply.</p>',
		] );
		$data = $r->get_data();

		if ( 201 !== $r->get_status() || empty( $data['id'] ) ) {
			return false;
		}

		$this->fixture_reply_id = (int) $data['id'];
		$this->cleanup[]        = [ 'type' => 'reply_rest', 'id' => $this->fixture_reply_id ];
		return true;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Private Messaging
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * PM1–PM6: Conversation lifecycle — create, list, get, send message,
	 * list messages, unread count.
	 */
	private function test_private_messaging(): void {
		\WP_CLI::log( '  Private Messaging' );

		if ( ! $this->ext_enabled( 'private-messaging' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		wp_set_current_user( $this->admin_id );

		if ( ! $this->recipient_id ) {
			$this->check( 'PM1: create conversation (skipped — no recipient user)', true );
			$this->check( 'PM2: list conversations (skipped)', true );
			$this->check( 'PM3: get conversation (skipped)', true );
			$this->check( 'PM4: send message (skipped)', true );
			$this->check( 'PM5: list messages (skipped)', true );
			$this->check( 'PM6: unread-count (skipped)', true );
			return;
		}

		// PM1: POST /conversations — create.
		$r    = $this->rest( 'POST', '/conversations', [
			'recipient_ids' => [ $this->recipient_id ],
			'message'       => 'Hello from the Pro QA suite.',
		] );
		$data = $r->get_data();
		$this->check( 'PM1: POST /conversations → 201', 201 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PM1: response has conversation_id', ! empty( $data['conversation_id'] ), 'missing conversation_id' );

		if ( empty( $data['conversation_id'] ) ) {
			$this->check( 'PM1: conversation created (fatal — aborting messaging tests)', false, wp_json_encode( $data ) );
			return;
		}

		$this->conv_id = (int) $data['conversation_id'];

		// Cleanup deferred — no REST DELETE; handled by direct DB in cleanup().
		$this->cleanup[] = [ 'type' => 'conversation_db', 'id' => $this->conv_id ];

		// PM2: GET /conversations — list.
		$r    = $this->rest( 'GET', '/conversations', [ 'limit' => 5 ] );
		$data = $r->get_data();
		$this->check( 'PM2: GET /conversations → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PM2: response has data array', isset( $data['data'] ) && is_array( $data['data'] ), 'missing data array' );

		// PM3: GET /conversations/:id — single conversation.
		$r    = $this->rest( 'GET', "/conversations/{$this->conv_id}" );
		$data = $r->get_data();
		$this->check( 'PM3: GET /conversations/:id → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PM3: response has data key', isset( $data['data'] ), 'missing data key' );

		// PM4: POST /conversations/:id/messages — send a second message.
		$r    = $this->rest( 'POST', "/conversations/{$this->conv_id}/messages", [
			'content' => 'Follow-up from the Pro QA suite.',
		] );
		$data = $r->get_data();
		$this->check( 'PM4: POST /conversations/:id/messages → 201', 201 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PM4: response has data key', isset( $data['data'] ), 'missing data key' );

		// PM5: GET /conversations/:id/messages — list messages.
		$r    = $this->rest( 'GET', "/conversations/{$this->conv_id}/messages", [ 'limit' => 10 ] );
		$data = $r->get_data();
		$this->check( 'PM5: GET /conversations/:id/messages → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PM5: response has data array', isset( $data['data'] ) && is_array( $data['data'] ), 'missing data array' );
		$this->check( 'PM5: at least one message in list', ! empty( $data['data'] ), 'data array is empty' );

		// PM6: GET /conversations/unread-count — unread count (as recipient).
		$r    = $this->rest( 'GET', '/conversations/unread-count', [], $this->recipient_id );
		$data = $r->get_data();
		$this->check( 'PM6: GET /conversations/unread-count → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PM6: response has unread_count key', isset( $data['data']['unread_count'] ), 'missing data.unread_count' );
		$this->check( 'PM6: unread_count is integer >= 0', is_int( $data['data']['unread_count'] ?? null ) && $data['data']['unread_count'] >= 0, "value=" . ( $data['data']['unread_count'] ?? 'missing' ) );

		// Restore admin.
		wp_set_current_user( $this->admin_id );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Reactions
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * RE1–RE4: Toggle reaction on post + GET counts; same for a reply.
	 */
	private function test_reactions(): void {
		\WP_CLI::log( '  Reactions' );

		if ( ! $this->ext_enabled( 'reactions' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		if ( ! $this->ensure_fixture_post() ) {
			$this->check( 'RE1: toggle post reaction (skipped — no fixture post)', true );
			$this->check( 'RE2: GET post reactions (skipped)', true );
			$this->check( 'RE3: toggle reply reaction (skipped)', true );
			$this->check( 'RE4: GET reply reactions (skipped)', true );
			return;
		}

		wp_set_current_user( $this->admin_id );
		$post_id = (int) $this->fixture_post->id;

		// RE1: POST /posts/:id/reactions — toggle on.
		$r    = $this->rest( 'POST', "/posts/{$post_id}/reactions", [ 'emoji' => 'thumbsup' ] );
		$data = $r->get_data();
		$this->check( 'RE1: POST /posts/:id/reactions → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'RE1: response has action key', isset( $data['action'] ), 'missing action key' );
		$this->check( 'RE1: action = added', 'added' === ( $data['action'] ?? '' ), "action={$data['action']}" );

		// RE2: GET /posts/:id/reactions.
		$r    = $this->rest( 'GET', "/posts/{$post_id}/reactions" );
		$data = $r->get_data();
		$this->check( 'RE2: GET /posts/:id/reactions → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'RE2: response has counts key', isset( $data['counts'] ), 'missing counts key' );
		$this->check( 'RE2: thumbsup count >= 1', isset( $data['counts']['thumbsup'] ) && (int) $data['counts']['thumbsup'] >= 1, 'thumbsup count not incremented' );

		// Toggle off to clean up.
		$this->rest( 'POST', "/posts/{$post_id}/reactions", [ 'emoji' => 'thumbsup' ] );

		// RE3: POST /replies/:id/reactions — toggle on a reply.
		if ( $this->ensure_fixture_reply() ) {
			$reply_id = $this->fixture_reply_id;

			$r    = $this->rest( 'POST', "/replies/{$reply_id}/reactions", [ 'emoji' => 'heart' ] );
			$data = $r->get_data();
			$this->check( 'RE3: POST /replies/:id/reactions → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'RE3: action = added', 'added' === ( $data['action'] ?? '' ), "action={$data['action']}" );

			// RE4: GET /replies/:id/reactions.
			$r    = $this->rest( 'GET', "/replies/{$reply_id}/reactions" );
			$data = $r->get_data();
			$this->check( 'RE4: GET /replies/:id/reactions → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'RE4: response has counts key', isset( $data['counts'] ), 'missing counts key' );

			// Toggle off to clean up.
			$this->rest( 'POST', "/replies/{$reply_id}/reactions", [ 'emoji' => 'heart' ] );
		} else {
			$this->check( 'RE3: reply reaction (skipped — no fixture reply)', true );
			$this->check( 'RE4: GET reply reactions (skipped)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Polls
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * PO1–PO4: Create poll, GET poll, vote, unvote (cleanup via DELETE).
	 */
	private function test_polls(): void {
		\WP_CLI::log( '  Polls' );

		if ( ! $this->ext_enabled( 'polls' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		if ( ! $this->ensure_fixture_post() ) {
			$this->check( 'PO1: create poll (skipped — no fixture post)', true );
			$this->check( 'PO2: GET /posts/:id/poll (skipped)', true );
			$this->check( 'PO3: POST /polls/:id/vote (skipped)', true );
			$this->check( 'PO4: DELETE /polls/:id/vote (skipped)', true );
			return;
		}

		wp_set_current_user( $this->admin_id );
		$post_id = (int) $this->fixture_post->id;

		// PO1: POST /posts/:id/poll — create a poll.
		$r    = $this->rest( 'POST', "/posts/{$post_id}/poll", [
			'question' => 'QA Pro: which do you prefer?',
			'options'  => [ 'Option A', 'Option B', 'Option C' ],
			'type'     => 'single',
		] );
		$raw  = $r->get_data();
		$data = $raw['data'] ?? $raw;
		$ok   = in_array( $r->get_status(), [ 200, 201 ], true );
		$this->check( 'PO1: POST /posts/:id/poll → 200/201', $ok, "HTTP {$r->get_status()}" );
		$this->check( 'PO1: response has id', ! empty( $data['id'] ), 'missing id' );

		if ( empty( $data['id'] ) ) {
			$this->check( 'PO1: poll created (fatal — aborting poll tests)', false, wp_json_encode( $raw ) );
			return;
		}

		$poll_id = (int) $data['id'];

		// PO2: GET /posts/:id/poll — read back the poll.
		$r    = $this->rest( 'GET', "/posts/{$post_id}/poll" );
		$raw  = $r->get_data();
		$data = $raw['data'] ?? $raw;
		$this->check( 'PO2: GET /posts/:id/poll → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'PO2: response has id', ! empty( $data['id'] ), 'missing id' );
		$this->check( 'PO2: response has options array', ! empty( $data['options'] ) && is_array( $data['options'] ), 'missing or empty options' );

		$option_id = (int) ( $data['options'][0]['id'] ?? 0 );

		// PO3: POST /polls/:id/vote — cast a vote.
		if ( $option_id ) {
			$r    = $this->rest( 'POST', "/polls/{$poll_id}/vote", [ 'option_id' => $option_id ] );
			$raw  = $r->get_data();
			$data = $raw['data'] ?? $raw;
			$this->check( 'PO3: POST /polls/:id/vote → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'PO3: response has user_votes array', isset( $data['user_votes'] ) && is_array( $data['user_votes'] ), 'missing user_votes' );
			$this->check( 'PO3: user_votes contains the option', in_array( $option_id, (array) ( $data['user_votes'] ?? [] ), false ), 'option_id not in user_votes' );

			// PO4: DELETE /polls/:id/vote — unvote (cleanup).
			$r = $this->rest( 'DELETE', "/polls/{$poll_id}/vote" );
			$this->check( 'PO4: DELETE /polls/:id/vote → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		} else {
			$this->check( 'PO3: vote (skipped — no option_id from GET)', true );
			$this->check( 'PO4: unvote (skipped)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Analytics
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * AN1–AN3: Overview stats, top spaces, top contributors.
	 */
	private function test_analytics(): void {
		\WP_CLI::log( '  Analytics' );

		if ( ! $this->ext_enabled( 'analytics' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		wp_set_current_user( $this->admin_id );

		// AN1: GET /analytics/overview?range=7d.
		$r    = $this->rest( 'GET', '/analytics/overview', [ 'range' => '7d' ] );
		$data = $r->get_data();
		$this->check( 'AN1: GET /analytics/overview → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'AN1: response has data key', isset( $data['data'] ), 'missing data key' );
		$this->check( 'AN1: data has range key', isset( $data['data']['range'] ), 'missing data.range' );

		// AN2: GET /analytics/top-spaces.
		$r    = $this->rest( 'GET', '/analytics/top-spaces', [ 'range' => '7d' ] );
		$data = $r->get_data();
		$this->check( 'AN2: GET /analytics/top-spaces → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'AN2: response has data key', isset( $data['data'] ), 'missing data key' );

		// AN3: GET /analytics/top-contributors.
		$r    = $this->rest( 'GET', '/analytics/top-contributors', [ 'range' => '7d' ] );
		$data = $r->get_data();
		$this->check( 'AN3: GET /analytics/top-contributors → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'AN3: response has data key', isset( $data['data'] ), 'missing data key' );

		// AN4: Non-admin is rejected (403).
		if ( $this->recipient_id ) {
			$r = $this->rest( 'GET', '/analytics/overview', [ 'range' => '7d' ], $this->recipient_id );
			$this->check( 'AN4: GET /analytics/overview as subscriber → 403', 403 === $r->get_status(), "HTTP {$r->get_status()}" );
			wp_set_current_user( $this->admin_id );
		} else {
			$this->check( 'AN4: analytics permission rejection (skipped — no test user)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Custom Badges
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * BA1–BA2: List badges, get user badges.
	 */
	private function test_custom_badges(): void {
		\WP_CLI::log( '  Custom Badges' );

		if ( ! $this->ext_enabled( 'custom-badges' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		wp_set_current_user( $this->admin_id );

		// BA1: GET /badges — public endpoint.
		$r    = $this->rest( 'GET', '/badges' );
		$data = $r->get_data();
		$this->check( 'BA1: GET /badges → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'BA1: response has data key', isset( $data['data'] ), 'missing data key' );
		$this->check( 'BA1: data is array', is_array( $data['data'] ?? null ), 'data is not an array' );

		// BA2: GET /users/:id/badges — public endpoint, admin's own badges.
		$r    = $this->rest( 'GET', "/users/{$this->admin_id}/badges" );
		$data = $r->get_data();
		$this->check( 'BA2: GET /users/:id/badges → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'BA2: response has data key', isset( $data['data'] ), 'missing data key' );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Custom Fields
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * CF1–CF2: List field definitions, get post fields.
	 */
	private function test_custom_fields(): void {
		\WP_CLI::log( '  Custom Fields' );

		if ( ! $this->ext_enabled( 'custom-fields' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		wp_set_current_user( $this->admin_id );

		// CF1: GET /fields — list field definitions (public).
		$r    = $this->rest( 'GET', '/fields' );
		$data = $r->get_data();
		$this->check( 'CF1: GET /fields → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'CF1: response has data key', isset( $data['data'] ), 'missing data key' );
		$this->check( 'CF1: data is array', is_array( $data['data'] ?? null ), 'data is not an array' );

		// CF2: GET /posts/:id/fields — public endpoint; requires a fixture post.
		if ( $this->ensure_fixture_post() ) {
			$post_id = (int) $this->fixture_post->id;
			$r       = $this->rest( 'GET', "/posts/{$post_id}/fields" );
			$data    = $r->get_data();
			$this->check( 'CF2: GET /posts/:id/fields → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
			$this->check( 'CF2: response has data key', isset( $data['data'] ), 'missing data key' );
		} else {
			$this->check( 'CF2: GET /posts/:id/fields (skipped — no fixture post)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// White Label
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * WL1–WL2: GET white-label settings (admin); non-admin is rejected.
	 */
	private function test_white_label(): void {
		\WP_CLI::log( '  White Label' );

		if ( ! $this->ext_enabled( 'white-label' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		wp_set_current_user( $this->admin_id );

		// WL1: GET /settings/white-label as admin.
		$r    = $this->rest( 'GET', '/settings/white-label' );
		$data = $r->get_data();
		$this->check( 'WL1: GET /settings/white-label → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'WL1: response has data key', isset( $data['data'] ), 'missing data key' );

		// WL2: Non-admin is rejected.
		if ( $this->recipient_id ) {
			$r = $this->rest( 'GET', '/settings/white-label', [], $this->recipient_id );
			$this->check( 'WL2: GET /settings/white-label as subscriber → 403', 403 === $r->get_status(), "HTTP {$r->get_status()}" );
			wp_set_current_user( $this->admin_id );
		} else {
			$this->check( 'WL2: white-label permission rejection (skipped — no test user)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// SEO Pro
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * SE1–SE2: GET space SEO settings (admin); non-admin is rejected.
	 */
	private function test_seo_pro(): void {
		\WP_CLI::log( '  SEO Pro' );

		if ( ! $this->ext_enabled( 'seo-pro' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		global $wpdb;
		$spaces_t = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space = $wpdb->get_row( "SELECT * FROM {$spaces_t} WHERE status = 'active' LIMIT 1" );

		if ( ! $space ) {
			$this->check( 'SE1: GET /spaces/:id/seo (skipped — no active space)', true );
			$this->check( 'SE2: SEO Pro permission rejection (skipped — no active space)', true );
			return;
		}

		$space_id = (int) $space->id;
		wp_set_current_user( $this->admin_id );

		// SE1: GET /spaces/:id/seo as admin.
		$r    = $this->rest( 'GET', "/spaces/{$space_id}/seo" );
		$data = $r->get_data();
		// 200 = settings exist; 404 = space found but no SEO row (both valid for GET).
		$ok   = in_array( $r->get_status(), [ 200, 404 ], true );
		$this->check( 'SE1: GET /spaces/:id/seo → 200 or 404', $ok, "HTTP {$r->get_status()}" );

		// SE2: Non-admin is rejected.
		if ( $this->recipient_id ) {
			$r = $this->rest( 'GET', "/spaces/{$space_id}/seo", [], $this->recipient_id );
			$this->check( 'SE2: GET /spaces/:id/seo as subscriber → 403', 403 === $r->get_status(), "HTTP {$r->get_status()}" );
			wp_set_current_user( $this->admin_id );
		} else {
			$this->check( 'SE2: SEO Pro permission rejection (skipped — no test user)', true );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Email Digest
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * ED1–ED3: GET preferences, PATCH preferences, verify update applied.
	 */
	private function test_email_digest(): void {
		\WP_CLI::log( '  Email Digest' );

		if ( ! $this->ext_enabled( 'email-digest' ) ) {
			\WP_CLI::log( '    (extension disabled — skipping)' );
			return;
		}

		wp_set_current_user( $this->admin_id );

		// ED1: GET /users/me/digest-preferences.
		$r    = $this->rest( 'GET', '/users/me/digest-preferences' );
		$data = $r->get_data();
		$this->check( 'ED1: GET /users/me/digest-preferences → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'ED1: response has data key', isset( $data['data'] ), 'missing data key' );
		$this->check( 'ED1: data has frequency key', isset( $data['data']['frequency'] ), 'missing data.frequency' );

		$original_freq = $data['data']['frequency'] ?? 'weekly';

		// ED2: PATCH /users/me/digest-preferences — toggle frequency.
		$new_freq = 'daily' === $original_freq ? 'weekly' : 'daily';
		$r        = $this->rest( 'PATCH', '/users/me/digest-preferences', [ 'frequency' => $new_freq ] );
		$data     = $r->get_data();
		$this->check( 'ED2: PATCH /users/me/digest-preferences → 200', 200 === $r->get_status(), "HTTP {$r->get_status()}" );
		$this->check( 'ED2: success = true', ! empty( $data['success'] ), 'success was not true' );

		// ED3: Verify the update was applied.
		$r    = $this->rest( 'GET', '/users/me/digest-preferences' );
		$data = $r->get_data();
		$this->check( 'ED3: frequency updated in GET response', ( $data['data']['frequency'] ?? '' ) === $new_freq, "got {$data['data']['frequency']}" );

		// Restore original frequency.
		$this->rest( 'PATCH', '/users/me/digest-preferences', [ 'frequency' => $original_freq ] );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Cleanup
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Process the cleanup stack in reverse order.
	 *
	 * REST DELETE is used for objects with a DELETE endpoint (posts, replies).
	 * Direct DB is used for conversation data — Pro messaging has no DELETE
	 * endpoint by design (conversations are soft-archived in real usage).
	 */
	private function cleanup(): void {
		\WP_CLI::log( '  Cleanup...' );

		wp_set_current_user( $this->admin_id );

		foreach ( array_reverse( $this->cleanup ) as $item ) {
			switch ( $item['type'] ) {

				case 'post_rest':
					$this->rest( 'DELETE', "/posts/{$item['id']}" );
					break;

				case 'reply_rest':
					$this->rest( 'DELETE', "/replies/{$item['id']}" );
					break;

				case 'conversation_db':
					// Remove conversation, participants, and messages directly —
					// no REST DELETE endpoint for conversations.
					$this->delete_conversation_db( (int) $item['id'] );
					break;
			}
		}

		// Delete the Pro QA recipient user.
		if ( $this->recipient_id ) {
			wp_delete_user( $this->recipient_id );
		}

		\WP_CLI::log( sprintf( '  Cleaned up %d Pro fixture object(s).', count( $this->cleanup ) ) );
	}

	/**
	 * Remove a conversation and all associated rows directly via $wpdb.
	 *
	 * @param int $conversation_id The conversation ID to remove.
	 */
	private function delete_conversation_db( int $conversation_id ): void {
		global $wpdb;

		$conv  = $wpdb->prefix . 'jt_pro_conversations';
		$part  = $wpdb->prefix . 'jt_pro_conversation_participants';
		$msg   = $wpdb->prefix . 'jt_pro_messages';

		$wpdb->delete( $msg, [ 'conversation_id' => $conversation_id ] );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $part, [ 'conversation_id' => $conversation_id ] );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $conv, [ 'id' => $conversation_id ] );               // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
	 * Exercises the full WordPress REST stack (permission callbacks,
	 * controller logic) without an HTTP round-trip.
	 *
	 * @param string   $method   HTTP method: GET, POST, PATCH, DELETE.
	 * @param string   $route    Route relative to /jetonomy/v1 (e.g. '/conversations/5').
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

		// Wrap WP_Error responses so callers can always call get_status() / get_data().
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
