<?php
/**
 * Smoke-test every journey shipped in the CLI module.
 *
 * @package Jetonomy
 */

namespace Jetonomy\QA;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Config_Journey;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\CLI\Journeys\Member_Journey;
use Jetonomy\CLI\Journeys\Moderation_Journey;
use Jetonomy\CLI\Journeys\Notification_Journey;
use Jetonomy\CLI\Journeys\Privacy_Journey;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;
use Jetonomy\CLI\Journeys\User_Journey;
use Jetonomy\CLI\Scenarios\Scenario_Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 4 of `wp jetonomy qa-actions`.
 *
 * Instantiates every journey shipped in the CLI module (C1–C9 free, C10–C12
 * Pro) and drives at least one read + one write path against a throwaway
 * fixture. Every write is tracked on a cleanup stack and reversed in
 * {@see cleanup()} so the QA run never leaves orphans behind.
 *
 * Pro journeys are optional — if `class_exists()` returns false, the Pro
 * block is skipped rather than failing. This keeps the smoke test usable
 * on free-only installs without adding a flag to `wp jetonomy qa-actions`.
 */
class Journey_Tests {

	private int $pass = 0;

	private int $fail = 0;

	/** @var array<int,array<string,mixed>> LIFO stack of fixtures to reverse. */
	private array $cleanup = [];

	/**
	 * Execute every journey smoke test in order and return pass/fail counts.
	 *
	 * @return array{pass:int,fail:int}
	 */
	public function run(): array {
		try {
			$this->test_content_journey();
			$this->test_reply_count_consistency();
			$this->test_reply_deep_link_targets();
			$this->test_space_journey();
			$this->test_member_journey();
			$this->test_moderation_journey();
			$this->test_notification_journey();
			$this->test_config_journey();
			$this->test_taxonomy_journey();
			$this->test_user_journey();
			$this->test_privacy_journey();
			$this->test_scenario_runner();
			$this->test_pro_extension_journey();
			$this->test_pro_messaging_journey();
			$this->test_pro_ai_journey();
			$this->test_pro_reactions_journey();
			$this->test_pro_polls_journey();
			$this->test_pro_custom_badges_journey();
			$this->test_pro_custom_fields_journey();
			$this->test_pro_email_digest_journey();
			$this->test_pro_web_push_journey();
			$this->test_pro_analytics_journey();
			$this->test_pro_advanced_moderation_journey();
			$this->test_pro_webhooks_journey();
			$this->test_pro_seo_pro_journey();
			$this->test_pro_white_label_journey();
			$this->test_pro_reply_by_email_journey();
		} catch ( \Throwable $e ) {
			$this->record( 'Journey_Tests uncaught', false, $e->getMessage() );
		} finally {
			$this->cleanup();
		}

		return [
			'pass' => $this->pass,
			'fail' => $this->fail,
		];
	}

	private function test_content_journey(): void {
		$journey = new Content_Journey();
		$space_id = $this->discover_open_space();
		if ( ! $space_id ) {
			$this->record( 'content: discover fixture space', false, 'no open space found' );
			return;
		}

		$author = $this->discover_user();

		$post_result = $journey->create_post(
			[
				'space_id'  => $space_id,
				'author_id' => $author,
				'title'     => 'QA journey probe ' . uniqid(),
				'content'   => 'QA journey probe body.',
			]
		);
		$this->record_result( 'content: create_post', $post_result );

		if ( ! $post_result->is_success() ) {
			return;
		}
		$post_id           = (int) $post_result->data['id'];
		$this->cleanup[]   = [ 'type' => 'post', 'id' => $post_id ];

		$reply_result = $journey->create_reply(
			[
				'post_id'   => $post_id,
				'author_id' => $author,
				'content'   => 'QA reply body.',
			]
		);
		$this->record_result( 'content: create_reply', $reply_result );
		if ( $reply_result->is_success() ) {
			$this->cleanup[] = [ 'type' => 'reply', 'id' => (int) $reply_result->data['id'] ];
		}

		$vote_result = $journey->vote( $author, 'post', $post_id, 1 );
		$this->record_result( 'content: vote', $vote_result );

		$flag_result = $journey->flag(
			[
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reporter_id' => $author,
				'reason'      => 'spam',
			]
		);
		$this->record_result( 'content: flag', $flag_result );
		if ( $flag_result->is_success() ) {
			$this->cleanup[] = [ 'type' => 'flag', 'id' => (int) $flag_result->data['id'] ];
		}
	}

	/**
	 * Regression guard: Reply::delete (the direct/CLI/fixture delete path) must
	 * reverse the reply_count increment that create() applied — mirroring the
	 * REST path's update( status => trash ). Catches the counter-drift fixed in
	 * the delete() method.
	 */
	private function test_reply_count_consistency(): void {
		$space_id = $this->discover_open_space();
		if ( ! $space_id ) {
			$this->record( 'content: reply_count consistency', false, 'no open space found' );
			return;
		}
		$author  = $this->discover_user();
		$post_id = \Jetonomy\Models\Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $author,
				'title'     => 'QA reply-count probe ' . uniqid(),
				'content'   => 'probe',
				'status'    => 'publish',
			]
		);
		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			$this->record( 'content: reply_count consistency', false, 'post create failed' );
			return;
		}
		$this->cleanup[] = [ 'type' => 'post', 'id' => $post_id ];

		$baseline = (int) ( \Jetonomy\Models\Post::find( $post_id )?->reply_count ?? 0 );
		$reply_id = \Jetonomy\Models\Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => $author,
				'content'   => 'probe reply',
				'status'    => 'publish',
			]
		);
		if ( ! is_int( $reply_id ) || $reply_id <= 0 ) {
			$this->record( 'content: reply_count consistency', false, 'reply create failed' );
			return;
		}
		$after_create = (int) ( \Jetonomy\Models\Post::find( $post_id )?->reply_count ?? 0 );
		\Jetonomy\Models\Reply::delete( $reply_id );
		$after_delete = (int) ( \Jetonomy\Models\Post::find( $post_id )?->reply_count ?? 0 );

		$ok = ( $after_create === $baseline + 1 ) && ( $after_delete === $baseline );
		$this->record(
			'content: reply_count consistency (create +1 / delete -1)',
			$ok,
			$ok ? '' : sprintf( 'baseline=%d create=%d delete=%d', $baseline, $after_create, $after_delete )
		);
	}

	/**
	 * A reply deep link must page to where the reply actually renders.
	 *
	 * Guards the ordering contract between Reply::page_of() (which computes a
	 * link's ?rpg) and Reply::get_threaded() (which slices the rendered page).
	 * They agree today only because both use Reply::DEFAULT_SORT and the same
	 * created_at/id tiebreak. Nothing structural stops a future change to
	 * either side, and the failure is silent — links keep working, they just
	 * land on the wrong page. So assert the two against each other.
	 *
	 * Covers the NESTED case explicitly: a child must resolve to its
	 * top-level ancestor's page, never a page of its own.
	 */
	private function test_reply_deep_link_targets(): void {
		$label    = 'content: reply deep link targets the page it renders on';
		$space_id = $this->discover_open_space();
		if ( ! $space_id ) {
			$this->record( $label, false, 'no open space found' );
			return;
		}
		$author  = $this->discover_user();
		$post_id = \Jetonomy\Models\Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $author,
				'title'     => 'QA reply deep-link probe ' . uniqid(),
				'content'   => 'probe',
				'status'    => 'publish',
			]
		);
		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			$this->record( $label, false, 'post create failed' );
			return;
		}
		$this->cleanup[] = [ 'type' => 'post', 'id' => $post_id ];

		// per_page is passed in rather than read from settings, so the probe
		// stays cheap (5 replies, not replies_per_page + 1) and independent of
		// the site's configured value.
		$per_page = 2;
		$top      = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$rid = \Jetonomy\Models\Reply::create(
				[
					'post_id'   => $post_id,
					'author_id' => $author,
					'content'   => 'probe top-level ' . $i,
					'status'    => 'publish',
					// Distinct, ordered timestamps: this test asserts the
					// page mapping, not MySQL's tie behaviour.
					'created_at' => gmdate( 'Y-m-d H:i:s', time() + $i ),
				]
			);
			if ( ! is_int( $rid ) || $rid <= 0 ) {
				$this->record( $label, false, 'reply create failed' );
				return;
			}
			$top[] = $rid;
		}

		// Child of the 4th top-level reply — that ancestor sits on page 2
		// under per_page=2, so the child must resolve to page 2 as well.
		$child = \Jetonomy\Models\Reply::create(
			[
				'post_id'    => $post_id,
				'author_id'  => $author,
				'parent_id'  => $top[3],
				'content'    => 'probe nested child',
				'status'     => 'publish',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() + 99 ),
			]
		);
		if ( ! is_int( $child ) || $child <= 0 ) {
			$this->record( $label, false, 'nested reply create failed' );
			return;
		}

		$mismatch = [];
		foreach ( array_merge( $top, [ $child ] ) as $rid ) {
			$claimed = \Jetonomy\Models\Reply::page_of( $rid, $per_page );

			// Where does the reply ACTUALLY render? Walk the pages the view
			// would render and find the one containing it (any depth).
			$actual = 0;
			for ( $page = 1; $page <= 5; $page++ ) {
				$batch = \Jetonomy\Models\Reply::get_threaded(
					$post_id,
					\Jetonomy\Models\Reply::DEFAULT_SORT,
					$per_page,
					( $page - 1 ) * $per_page
				);
				if ( \Jetonomy\Models\Reply::tree_contains( $batch, $rid ) ) {
					$actual = $page;
					break;
				}
			}

			if ( $claimed !== $actual ) {
				$mismatch[] = sprintf( 'reply %d: link says page %d, renders on page %d', $rid, $claimed, $actual );
			}
		}

		$ok = empty( $mismatch );
		$this->record( $label, $ok, $ok ? '' : implode( '; ', $mismatch ) );

		// Nested child specifically resolves to its ancestor's page.
		$child_page  = \Jetonomy\Models\Reply::page_of( $child, $per_page );
		$parent_page = \Jetonomy\Models\Reply::page_of( $top[3], $per_page );
		$this->record(
			'content: nested reply deep link resolves to its top-level ancestor page',
			$child_page === $parent_page,
			$child_page === $parent_page ? '' : sprintf( 'child page %d != ancestor page %d', $child_page, $parent_page )
		);
	}

	private function test_space_journey(): void {
		$journey = new Space_Journey();
		$suffix  = uniqid( 'qa_space_', false );

		$result = $journey->create(
			[
				'title'       => 'QA journey space',
				'slug'        => 'qa-journey-' . $suffix,
				'category_id' => $this->discover_category(),
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$this->record_result( 'space: create', $result );
		if ( ! $result->is_success() ) {
			return;
		}
		$space_id        = (int) $result->data['id'];
		$this->cleanup[] = [ 'type' => 'space', 'id' => $space_id ];

		$this->record_result( 'space: set_join_policy', $journey->set_join_policy( $space_id, 'approval' ) );
		$this->record_result( 'space: set_visibility', $journey->set_visibility( $space_id, 'private' ) );
		$this->record_result( 'space: get', $journey->get( $space_id ) );
	}

	private function test_member_journey(): void {
		$journey  = new Member_Journey();
		$space_id = $this->discover_open_space();
		$user_id  = $this->discover_secondary_user();

		if ( ! $space_id || ! $user_id ) {
			$this->record( 'member: discover fixtures', false, 'missing space or user' );
			return;
		}

		$join_result = $journey->join( $space_id, $user_id );
		$this->record_result( 'member: join', $join_result );
		if ( $join_result->is_success() ) {
			$this->cleanup[] = [ 'type' => 'space_member', 'space_id' => $space_id, 'user_id' => $user_id ];
		}

		$this->record_result( 'member: is_member', $journey->is_member( $space_id, $user_id ) );
		$this->record_result( 'member: get_role', $journey->get_role( $space_id, $user_id ) );
	}

	private function test_moderation_journey(): void {
		$journey = new Moderation_Journey();
		$this->record_result( 'moderation: list_pending_flags', $journey->list_pending_flags() );
		$this->record_result( 'moderation: list_by_status pending', $journey->list_flags_by_status( 'pending' ) );
	}

	private function test_notification_journey(): void {
		$journey = new Notification_Journey();
		$user_id = $this->discover_user();

		$this->record_result( 'notification: unread_count', $journey->unread_count( $user_id ) );

		$trigger_result = $journey->trigger(
			[
				'type'        => 'moderation',
				'user_id'     => $user_id,
				'actor_id'    => $user_id,
				'object_type' => 'post',
				'object_id'   => 1,
				'message'     => 'QA smoke notification.',
			]
		);
		$this->record_result( 'notification: trigger', $trigger_result );
		if ( $trigger_result->is_success() ) {
			$this->cleanup[] = [ 'type' => 'notification', 'id' => (int) $trigger_result->data['id'] ];
		}
	}

	private function test_config_journey(): void {
		$journey = new Config_Journey();
		$this->record_result( 'config: get full', $journey->get() );
		$this->record_result( 'config: get dotted', $journey->get( 'trust_thresholds.1.posts' ) );
		$this->record_result( 'config: list_keys', $journey->list_keys() );
	}

	private function test_taxonomy_journey(): void {
		$journey = new Taxonomy_Journey();
		$this->record_result( 'taxonomy: list_top_level_categories', $journey->list_top_level_categories() );

		$suffix      = uniqid( 'qa_cat_', false );
		$cat_result  = $journey->create_category(
			[
				'name' => 'QA journey cat',
				'slug' => 'qa-journey-' . $suffix,
			]
		);
		$this->record_result( 'taxonomy: create_category', $cat_result );
		if ( $cat_result->is_success() ) {
			$this->cleanup[] = [ 'type' => 'category', 'id' => (int) $cat_result->data['id'] ];
		}

		$tag_result = $journey->create_or_get_tag( 'qa-journey-tag-' . uniqid( '', false ) );
		$this->record_result( 'taxonomy: create_or_get_tag', $tag_result );
		if ( $tag_result->is_success() ) {
			$this->cleanup[] = [ 'type' => 'tag', 'id' => (int) $tag_result->data['id'] ];
		}
	}

	private function test_user_journey(): void {
		$journey = new User_Journey();
		$suffix  = uniqid( '', false );

		$create_result = $journey->create_with_trust_level(
			[
				'login'        => 'qa_journey_' . $suffix,
				'email'        => 'qa_journey_' . $suffix . '@example.test',
				'display_name' => 'QA Journey User',
				'trust_level'  => 1,
			]
		);
		$this->record_result( 'user: create_with_trust_level', $create_result );
		if ( ! $create_result->is_success() ) {
			return;
		}
		$user_id         = (int) $create_result->data['user_id'];
		$this->cleanup[] = [ 'type' => 'user', 'id' => $user_id ];

		$this->record_result( 'user: set_trust_level', $journey->set_trust_level( $user_id, 2 ) );
		$this->record_result( 'user: get_trust_level', $journey->get_trust_level( $user_id ) );
		$this->record_result( 'user: adjust_reputation', $journey->adjust_reputation( $user_id, 5 ) );
		$this->record_result( 'user: get_profile', $journey->get_profile( $user_id ) );
	}

	/**
	 * Scan only — deliberately never calls purge_orphans().
	 *
	 * qa-actions runs against live installs, and a smoke test has no business
	 * deleting rows as a side effect of being run. The scan still exercises the
	 * whole discovery path (free's columns + Pro's contributed ones + the SQL),
	 * which is where the bugs would be; the purge itself is the shared
	 * on_user_delete() body already covered by the rest of this suite.
	 */
	private function test_privacy_journey(): void {
		$journey = new Privacy_Journey();
		$result  = $journey->scan_orphans();
		$this->record_result( 'privacy: scan_orphans', $result );

		if ( ! $result->is_success() ) {
			return;
		}
		$this->record(
			'privacy: orphan report shape',
			isset( $result->data['orphan_rows'], $result->data['orphan_users'], $result->data['columns'] ),
			sprintf( '%d orphan row(s)', (int) ( $result->data['orphan_rows'] ?? -1 ) )
		);
	}

	private function test_scenario_runner(): void {
		$runner = new Scenario_Runner();
		$list   = $runner->list();
		$this->record( 'scenario: list', ! empty( $list ), sprintf( 'found %d', count( $list ) ) );
	}

	private function test_pro_extension_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Extension_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.extension: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.extension: list_all', $journey->list_all() );
		$this->record_result( 'pro.extension: status ai', $journey->status( 'ai' ) );
	}

	private function test_pro_messaging_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Messaging_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.messaging: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.messaging: unread_count', $journey->unread_count( $this->discover_user() ) );
		$this->record_result( 'pro.messaging: list_conversations', $journey->list_conversations( $this->discover_user(), 5 ) );
	}

	private function test_pro_ai_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\AI_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.ai: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.ai: is_enabled', $journey->is_enabled() );
		$this->record_result( 'pro.ai: list_providers', $journey->list_providers() );
	}

	private function test_pro_reactions_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Reactions_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.reactions: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.reactions: list_available_emojis', $journey->list_available_emojis() );
		$this->record_result( 'pro.reactions: count_reactions', $journey->count_reactions( 1, 'post' ) );
	}

	private function test_pro_polls_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Polls_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.polls: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		// Read-only probe: a missing poll returns a failing result which is the expected shape.
		$result = $journey->get_poll( 1 );
		$this->record( 'pro.polls: get_poll returns journey result', $result instanceof \Jetonomy\CLI\Journey_Result, '' );
	}

	private function test_pro_custom_badges_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Custom_Badges_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.badges: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.badges: list_badges', $journey->list_badges() );
	}

	private function test_pro_custom_fields_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Custom_Fields_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.fields: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.fields: list_fields', $journey->list_fields() );
	}

	private function test_pro_email_digest_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Email_Digest_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.email-digest: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.email-digest: get_preferences', $journey->get_preferences( $this->discover_user() ) );
		$this->record_result( 'pro.email-digest: get_stats', $journey->get_stats() );
	}

	private function test_pro_web_push_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Web_Push_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.web-push: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.web-push: get_vapid_public_key', $journey->get_vapid_public_key() );
		$this->record_result( 'pro.web-push: count_subscribers', $journey->count_subscribers() );
	}

	private function test_pro_analytics_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Analytics_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.analytics: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.analytics: overview', $journey->overview( '7d', null, null ) );
		$this->record_result( 'pro.analytics: engagement', $journey->engagement( '7d' ) );
	}

	private function test_pro_advanced_moderation_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Advanced_Moderation_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.advanced-moderation: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.advanced-moderation: list_rules', $journey->list_rules() );
	}

	private function test_pro_webhooks_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Webhooks_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.webhooks: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.webhooks: list_webhooks', $journey->list_webhooks() );
		$this->record_result( 'pro.webhooks: list_supported_events', $journey->list_supported_events() );
	}

	private function test_pro_seo_pro_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Seo_Pro_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.seo-pro: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.seo-pro: get_global_defaults', $journey->get_global_defaults() );
	}

	private function test_pro_white_label_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\White_Label_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.white-label: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.white-label: get_settings', $journey->get_settings() );
		$this->record_result( 'pro.white-label: preview_branding', $journey->preview_branding() );
	}

	private function test_pro_reply_by_email_journey(): void {
		$class = 'Jetonomy_Pro\\CLI\\Journeys\\Reply_By_Email_Journey';
		if ( ! class_exists( $class ) ) {
			$this->record( 'pro.reply-by-email: class available', true, 'skipped — Pro not loaded' );
			return;
		}
		$journey = new $class();
		$this->record_result( 'pro.reply-by-email: get_config', $journey->get_config() );
	}

	/**
	 * Reverse every fixture the smoke tests created, in LIFO order.
	 *
	 * Cleanup errors are logged as warnings but never fail the phase — if
	 * something went sideways mid-test the rest of the stack still unwinds
	 * as best-effort so QA doesn't have to manually clean the DB.
	 */
	private function cleanup(): void {
		if ( empty( $this->cleanup ) ) {
			return;
		}

		$count = 0;
		foreach ( array_reverse( $this->cleanup ) as $fixture ) {
			try {
				$this->delete_fixture( $fixture );
				++$count;
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( 'Journey cleanup failed for %s: %s', $fixture['type'] ?? 'unknown', $e->getMessage() ) );
			}
		}
		\WP_CLI::log( sprintf( '  [cleanup] %d journey fixture(s) reversed.', $count ) );
	}

	/**
	 * @param array<string,mixed> $fixture
	 */
	private function delete_fixture( array $fixture ): void {
		$type = (string) ( $fixture['type'] ?? '' );
		switch ( $type ) {
			case 'post':
				\Jetonomy\Models\Post::delete( (int) $fixture['id'] );
				break;
			case 'reply':
				\Jetonomy\Models\Reply::delete( (int) $fixture['id'] );
				break;
			case 'space':
				\Jetonomy\Models\Space::delete( (int) $fixture['id'] );
				break;
			case 'space_member':
				\Jetonomy\Models\SpaceMember::remove( (int) $fixture['space_id'], (int) $fixture['user_id'] );
				break;
			case 'flag':
				global $wpdb;
				$wpdb->delete( \Jetonomy\table( 'flags' ), [ 'id' => (int) $fixture['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				break;
			case 'notification':
				global $wpdb;
				$wpdb->delete( \Jetonomy\table( 'notifications' ), [ 'id' => (int) $fixture['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				break;
			case 'category':
				\Jetonomy\Models\Category::delete( (int) $fixture['id'] );
				break;
			case 'tag':
				\Jetonomy\Models\Tag::delete( (int) $fixture['id'] );
				break;
			case 'user':
				if ( function_exists( 'wp_delete_user' ) ) {
					wp_delete_user( (int) $fixture['id'] );
				}
				break;
		}
	}

	/**
	 * Locate any open-policy space with at least one existing member so that
	 * create_post + vote calls hit an already-seeded fixture.
	 */
	private function discover_open_space(): int {
		global $wpdb;
		$table = \Jetonomy\table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( "SELECT id FROM {$table} WHERE join_policy = 'open' AND status = 'active' ORDER BY id ASC LIMIT 1" );
		return (int) $id;
	}

	private function discover_category(): int {
		global $wpdb;
		$table = \Jetonomy\table( 'categories' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );
		return (int) $id;
	}

	private function discover_user(): int {
		$admin = get_user_by( 'id', 1 );
		return $admin ? 1 : (int) get_current_user_id();
	}

	private function discover_secondary_user(): int {
		$users = get_users(
			[
				'number'  => 1,
				'exclude' => [ 1 ],
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			]
		);
		return $users ? (int) $users[0] : 0;
	}

	private function record_result( string $label, Journey_Result $result ): void {
		$this->record( $label, $result->is_success(), $result->is_success() ? '' : (string) $result->first_error() );
	}

	private function record( string $label, bool $ok, string $detail = '' ): void {
		if ( $ok ) {
			++$this->pass;
			\WP_CLI::log( sprintf( '  [PASS] %s%s', $label, $detail ? ' — ' . $detail : '' ) );
		} else {
			++$this->fail;
			\WP_CLI::warning( sprintf( '  [FAIL] %s%s', $label, $detail ? ' — ' . $detail : '' ) );
		}
	}
}
