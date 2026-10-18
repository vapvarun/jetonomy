<?php
/**
 * Fixes from the wave-3 moderation walks (2026-10-17): already-handled guards
 * on flags and queue actions, refusal parity for staff routes, silenced
 * moderators, merged and member reports on the queue, the flag-content and
 * vote abilities, self-votes, report note length, accepted answers that are
 * removed, and private replies read one at a time.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Vote;
use Jetonomy\Moderation\Moderation_Service;
use Jetonomy\Permissions\Capabilities;

class ModerationWalkFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $moderator;
	private int $author;
	private int $reporter;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		Restriction::reset_memo();
		\Jetonomy\Cache::flush();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$this->moderator = $this->user( array( 'jetonomy_moderate', 'jetonomy_edit_others_posts', 'jetonomy_delete_others_posts' ) );
		$this->author    = $this->user();
		$this->reporter  = $this->user();

		do_action( 'rest_api_init' );
	}

	private function user( array $extra = array() ): int {
		$id      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$account = new \WP_User( $id );
		foreach ( array_merge( array( 'jetonomy_read', 'jetonomy_create_posts', 'jetonomy_create_replies', 'jetonomy_vote', 'jetonomy_flag', 'jetonomy_join_spaces' ), $extra ) as $cap ) {
			$account->add_cap( $cap );
		}
		UserProfile::find_or_create( $id );
		return $id;
	}

	private function space( array $data = array() ): int {
		$id = Space::create(
			array_merge(
				array(
					'title'      => 'MW ' . uniqid(),
					'slug'       => 'mw-' . uniqid(),
					'visibility' => 'public',
				),
				$data
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	private function topic( int $space_id, int $author, array $data = array() ): int {
		return (int) Post::create(
			array_merge(
				array(
					'space_id'  => $space_id,
					'author_id' => $author,
					'title'     => 'MW topic ' . uniqid(),
					'slug'      => 'mw-topic-' . uniqid(),
					'content'   => '<p>Body</p>',
					'status'    => 'publish',
				),
				$data
			)
		);
	}

	private function reply( int $post_id, int $author, array $data = array() ): int {
		return (int) Reply::create(
			array_merge(
				array(
					'post_id'   => $post_id,
					'author_id' => $author,
					'content'   => '<p>Reply body</p>',
					'status'    => 'publish',
				),
				$data
			)
		);
	}

	private function flag( string $type, int $id, int $reporter, string $reason = 'spam' ): int {
		return Flag::create(
			array(
				'reporter_id' => $reporter,
				'object_type' => $type,
				'object_id'   => $id,
				'reason'      => $reason,
			)
		);
	}

	private function rest( string $method, string $route, array $params, int $as ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}

	private function notices( int $user_id ): int {
		return Notification::count_for_user( $user_id );
	}

	/** Item 1: a second resolve of the same report is refused, and the reporter hears once. */
	public function test_resolving_a_report_twice_is_refused_and_notifies_once(): void {
		$post = $this->topic( $this->space(), $this->author );
		$flag = $this->flag( 'post', $post, $this->reporter );
		$before = $this->notices( $this->reporter );

		$first = $this->rest( 'POST', "/moderation/flags/{$flag}/resolve", array( 'status' => 'dismissed' ), $this->admin );
		$this->assertSame( 200, $first->get_status() );
		$after_first = $this->notices( $this->reporter );

		foreach ( array( 'dismissed', 'valid' ) as $status ) {
			$again = $this->rest( 'POST', "/moderation/flags/{$flag}/resolve", array( 'status' => $status ), $this->admin );
			$this->assertSame( 409, $again->get_status() );
			$this->assertSame( 'jetonomy_already_handled', $again->get_data()['code'] );
		}

		$this->assertSame( $before + 1, $after_first, 'The first resolve notifies the reporter.' );
		$this->assertSame( $after_first, $this->notices( $this->reporter ), 'A refused resolve sends nothing.' );
		$this->assertSame( 'publish', Post::find( $post )->status, 'A refused Remove trashes nothing.' );
	}

	/** Item 2: approve twice, then a stale Reject, on a held topic. */
	public function test_queue_actions_refuse_already_handled_items(): void {
		$space = $this->space();
		$post  = $this->topic( $space, $this->author, array( 'status' => 'pending' ) );
		$base  = "/spaces/{$space}/moderation";

		$this->assertSame( 200, $this->rest( 'POST', "{$base}/approve/post/{$post}", array( 'from' => 'pending' ), $this->admin )->get_status() );
		$after_approve = $this->notices( $this->author );

		$twice = $this->rest( 'POST', "{$base}/approve/post/{$post}", array( 'from' => 'pending' ), $this->admin );
		$this->assertSame( 409, $twice->get_status() );
		$this->assertSame( 'jetonomy_already_handled', $twice->get_data()['code'] );

		// The Reject on a card that still shows "Awaiting approval".
		$stale = $this->rest( 'POST', "{$base}/trash/post/{$post}", array( 'from' => 'pending' ), $this->admin );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'publish', Post::find( $post )->status );

		// The site-wide routes and spam carry the same guard.
		$this->assertSame( 409, $this->rest( 'POST', "/moderation/spam/post/{$post}", array( 'from' => 'pending' ), $this->admin )->get_status() );
		$this->assertSame( 409, $this->rest( 'POST', "/moderation/approve/post/{$post}", array(), $this->admin )->get_status() );
		$this->assertSame( $after_approve, $this->notices( $this->author ), 'Refused actions send nothing.' );

		// Trash, then trash again (no `from`: a same-status action is always handled).
		$this->assertSame( 200, $this->rest( 'POST', "/moderation/trash/post/{$post}", array(), $this->admin )->get_status() );
		$after_trash = $this->notices( $this->author );
		$this->assertSame( 409, $this->rest( 'POST', "/moderation/trash/post/{$post}", array(), $this->admin )->get_status() );
		$this->assertSame( $after_trash, $this->notices( $this->author ) );
		// Restore twice: the second is handled already.
		$this->assertSame( 200, $this->rest( 'POST', "{$base}/approve/post/{$post}", array( 'from' => 'trash' ), $this->admin )->get_status() );
		$after_restore = $this->notices( $this->author );
		$this->assertSame( 409, $this->rest( 'POST', "{$base}/approve/post/{$post}", array( 'from' => 'trash' ), $this->admin )->get_status() );

		$this->assertSame( $after_restore, $this->notices( $this->author ), 'Refused actions send nothing.' );
	}

	/** Item 3: a moderator who cannot read the space gets the missing-report answer. */
	public function test_report_in_unreadable_space_answers_like_missing(): void {
		$private = $this->space(
			array(
				'visibility'  => 'private',
				'join_policy' => 'approval',
			)
		);
		$post    = $this->topic( $private, $this->author );
		$flag    = $this->flag( 'post', $post, $this->admin );

		$hidden  = $this->rest( 'POST', "/moderation/flags/{$flag}/resolve", array( 'status' => 'dismissed' ), $this->moderator );
		$missing = $this->rest( 'POST', '/moderation/flags/99999999/resolve', array( 'status' => 'dismissed' ), $this->moderator );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( $missing->get_status(), $hidden->get_status() );
		$this->assertSame( $missing->get_data(), $hidden->get_data() );
		$this->assertSame( 'pending', Flag::find( $flag )->status );
	}

	/** Item 4: space-scoped staff routes answer a hidden space like a missing one. */
	public function test_hidden_space_routes_answer_like_missing(): void {
		$hidden = $this->space(
			array(
				'visibility'  => 'hidden',
				'join_policy' => 'invite',
			)
		);
		$member = $this->user();
		$routes = array(
			array( 'GET', '/spaces/%d/moderation/flags', array() ),
			array( 'POST', '/spaces/%d/moderation/flags/1/resolve', array( 'status' => 'dismissed' ) ),
			array( 'POST', '/spaces/%d/moderation/trash/post/1', array() ),
			array( 'GET', '/spaces/%d/join-requests', array() ),
		);
		foreach ( $routes as $route ) {
			$hidden_res  = $this->rest( $route[0], sprintf( $route[1], $hidden ), $route[2], $member );
			$missing_res = $this->rest( $route[0], sprintf( $route[1], 99999999 ), $route[2], $member );
			$this->assertSame( 404, $hidden_res->get_status(), $route[1] );
			$this->assertSame( $missing_res->get_data(), $hidden_res->get_data(), $route[1] );
		}
	}

	/** Item 5: guests are told to sign in on every staff read route. */
	public function test_guest_gets_401_on_staff_routes(): void {
		foreach ( array( '/moderation/queue', '/moderation/flags', '/moderation/ban', '/users' ) as $route ) {
			$guest = $this->rest( 'GET', $route, array(), 0 );
			$this->assertSame( 401, $guest->get_status(), $route );
			$this->assertSame( 403, $this->rest( 'GET', $route, array(), $this->author )->get_status(), $route );
		}
	}

	/** Item 6: a report on content its author already deleted offers Dismiss only. */
	public function test_card_for_removed_content_offers_dismiss_only(): void {
		$space = $this->space();
		$post  = $this->topic( $space, $this->author );
		$reply = $this->reply( $post, $this->author );
		$flag  = $this->flag( 'reply', $reply, $this->reporter );
		Reply::update( $reply, array( 'status' => 'trash' ) );

		$html = $this->card( $flag, $space );

		$this->assertStringContainsString( 'already removed', $html );
		$this->assertStringNotContainsString( 'data-resolution="valid"', $html );
		$this->assertStringContainsString( 'data-resolution="dismissed"', $html );

		// Dismissing it still works.
		$this->assertSame( 200, $this->rest( 'POST', "/moderation/flags/{$flag}/resolve", array( 'status' => 'dismissed' ), $this->admin )->get_status() );
	}

	/** Item 7: the held-content card offers Spam and names the status it shows. */
	public function test_approval_card_offers_spam(): void {
		$space = $this->space();
		$post  = $this->topic( $space, $this->author, array( 'status' => 'pending' ) );

		ob_start();
		\Jetonomy\Template_Loader::partial(
			'moderation/approval-card',
			array(
				'item'  => Post::find( $post ),
				'kind'  => 'post',
				'space' => Space::find( $space ),
				'base'  => \Jetonomy\base_url(),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-action-name="spam"', $html );
		$this->assertStringContainsString( 'data-object-status="pending"', $html );
	}

	/** Item 8: a silenced moderator takes no moderation action, on any entry point. */
	public function test_silenced_moderator_is_refused_with_the_reason(): void {
		$space = $this->space();
		$post  = $this->topic( $space, $this->author );
		$flag  = $this->flag( 'post', $post, $this->reporter );
		Restriction::ban( $this->moderator, 'silence', $this->admin );
		Restriction::reset_memo();
		\Jetonomy\Cache::flush();

		$routes = array(
			array( "/spaces/{$space}/moderation/trash/post/{$post}", array() ),
			array( "/moderation/trash/post/{$post}", array() ),
			array( "/moderation/flags/{$flag}/resolve", array( 'status' => 'dismissed' ) ),
			array(
				'/moderation/ban',
				array(
					'user_id' => $this->author,
					'type'    => 'silence',
				),
			),
		);
		foreach ( $routes as $route ) {
			$res = $this->rest( 'POST', $route[0], $route[1], $this->moderator );
			$this->assertSame( 403, $res->get_status(), $route[0] );
			$this->assertSame( 'jetonomy_user_silenced', $res->get_data()['code'], $route[0] );
		}
		$this->assertSame( 'publish', Post::find( $post )->status );
		$this->assertSame( 'pending', Flag::find( $flag )->status );

		// The abilities and admin AJAX paths share the service.
		$err = Moderation_Service::set_object_status( $this->moderator, 'post', $post, 'trash' );
		$this->assertWPError( $err );
		$this->assertSame( 'jetonomy_user_silenced', $err->get_error_code() );
		$err = Moderation_Service::system_set_object_status( 'post', $post, 'trash', $this->moderator );
		$this->assertWPError( $err );
	}

	/** Item 9: the flag-content ability files a report the way POST /flags does. */
	public function test_flag_content_ability_matches_rest(): void {
		$post      = $this->topic( $this->space(), $this->author );
		$abilities = new \Jetonomy\Abilities();
		wp_set_current_user( $this->reporter );

		$result = $abilities->execute_flag_content(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'reason'      => 'off-topic',
				'details'     => 'Wrong place.',
			)
		);
		$this->assertIsArray( $result );
		$row = Flag::find( (int) $result['flag_id'] );
		$this->assertSame( (string) $this->reporter, (string) $row->reporter_id );
		$this->assertSame( 'off_topic', $row->reason );
		$this->assertSame( 'Wrong place.', $row->description );

		$again = $abilities->execute_flag_content(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'reason'      => 'inappropriate',
			)
		);
		$this->assertSame( 'jetonomy_already_flagged', $again->get_error_code() );

		wp_set_current_user( $this->author );
		$own = $abilities->execute_flag_content(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'reason'      => 'spam',
			)
		);
		$this->assertSame( 'jetonomy_flag_self', $own->get_error_code() );
	}

	/** Item 10: your own vote never counts, on REST, the ability and the model. */
	public function test_self_votes_are_refused_on_every_entry_point(): void {
		$space = $this->space();
		$post  = $this->topic( $space, $this->author );
		$reply = $this->reply( $post, $this->author );

		$rest = $this->rest( 'POST', "/posts/{$post}/vote", array( 'value' => 1 ), $this->author );
		$this->assertSame( 400, $rest->get_status() );
		$this->assertSame( 'jetonomy_self_vote', $rest->get_data()['code'] );

		$this->assertSame( 400, $this->rest( 'POST', "/replies/{$reply}/vote", array( 'value' => 1 ), $this->author )->get_status() );

		wp_set_current_user( $this->author );
		$ability = ( new \Jetonomy\Abilities() )->execute_vote(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'value'       => 1,
			)
		);
		$this->assertWPError( $ability );

		$this->assertWPError( Vote::cast( $this->author, 'reply', $reply, 1 ) );
		$this->assertSame( 0, (int) Post::find( $post )->vote_score );
		$this->assertSame( 0, (int) Reply::find( $reply )->vote_score );

		// The UI shows the control disabled, with the reason.
		ob_start();
		jetonomy_vote_denied_control( 0, 14, Space::find( $space ), true );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'You cannot vote on your own content.', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );

		// Someone else's vote still counts.
		$this->assertSame( 200, $this->rest( 'POST', "/posts/{$post}/vote", array( 'value' => 1 ), $this->reporter )->get_status() );
	}

	/** Item 11: a silenced member's vote control names the silence. */
	public function test_silenced_member_vote_control_names_the_reason(): void {
		$space = $this->space();
		Restriction::ban( $this->reporter, 'silence', $this->admin );
		Restriction::reset_memo();
		wp_set_current_user( $this->reporter );

		ob_start();
		jetonomy_vote_denied_control( 3, 14, Space::find( $space ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'silenced', $html );
		$this->assertStringNotContainsString( 'Voting is not available to you here.', $html );
	}

	/** Item 12: an over-long report note is a 400 with the limit, never a 500. */
	public function test_report_note_over_the_limit_is_a_400(): void {
		$post = $this->topic( $this->space(), $this->author );

		$long = $this->rest(
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'reason'      => 'spam',
				'description' => str_repeat( 'a', Flag::DESCRIPTION_MAX + 1 ),
			),
			$this->reporter
		);
		$this->assertSame( 400, $long->get_status() );
		$this->assertStringContainsString( number_format_i18n( Flag::DESCRIPTION_MAX ), $long->get_data()['message'] );

		$ok = $this->rest(
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'reason'      => 'spam',
				'description' => str_repeat( 'a', Flag::DESCRIPTION_MAX ),
			),
			$this->reporter
		);
		$this->assertSame( 201, $ok->get_status() );
	}

	/** Item 13: a reported member is a card on the overview, and can be resolved. */
	public function test_member_report_renders_on_the_overview(): void {
		$report = $this->flag( 'user', $this->author, $this->reporter, 'harassment' );

		$this->assertSame( 1, Moderation_Service::count_pending_flags( $this->admin ) );
		$ctx = Moderation_Service::flag_context( Moderation_Service::list_pending_flags( $this->admin, null, 25, 0 ), \Jetonomy\base_url() );
		$this->assertArrayHasKey( 'user:' . $this->author, $ctx );

		wp_set_current_user( $this->admin );
		ob_start();
		include JETONOMY_DIR . 'templates/views/moderation.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-flag-id="' . $report . '"', $html );
		$this->assertStringContainsString( 'data-resolution="dismissed"', $html );

		// Upholding a member report removes nothing and does not fail.
		$this->assertSame( 200, $this->rest( 'POST', "/moderation/flags/{$report}/resolve", array( 'status' => 'valid' ), $this->admin )->get_status() );
		$this->assertSame( 'valid', Flag::find( $report )->status );
	}

	/** Item 14: three reports on one reply are one card, and one decision resolves them all. */
	public function test_reports_on_the_same_reply_merge(): void {
		$space     = $this->space();
		$post      = $this->topic( $space, $this->author );
		$reply     = $this->reply( $post, $this->author );
		$reporters = array( $this->reporter, $this->user(), $this->user() );
		$first     = $this->flag( 'reply', $reply, $reporters[0], 'spam' );
		$this->flag( 'reply', $reply, $reporters[1], 'spam' );
		$last = $this->flag( 'reply', $reply, $reporters[2], 'offensive' );

		$rows = Moderation_Service::list_pending_flags( $this->admin, $space, 25, 0 );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $last, (string) $rows[0]->id );
		$this->assertSame( 1, Moderation_Service::count_pending_flags( $this->admin, $space ) );
		$this->assertSame( 1, Moderation_Service::count_pending_flags( $this->admin ) );

		$ctx = Moderation_Service::flag_context( $rows, \Jetonomy\base_url() )[ 'reply:' . $reply ];
		$this->assertSame( 3, $ctx->reports['count'] );
		$this->assertEquals(
			array(
				'offensive' => 1,
				'spam'      => 2,
			),
			$ctx->reports['reasons']
		);
		$html = $this->card( (int) $rows[0]->id, $space );
		$this->assertStringContainsString( '3 reports', $html );

		$before = array_map( array( $this, 'notices' ), $reporters );
		$this->assertSame( 200, $this->rest( 'POST', "/moderation/flags/{$last}/resolve", array( 'status' => 'dismissed' ), $this->admin )->get_status() );
		$this->assertSame( 'dismissed', Flag::find( $first )->status );
		$this->assertSame( 0, Moderation_Service::count_pending_flags( $this->admin ) );
		foreach ( $reporters as $i => $id ) {
			$this->assertSame( $before[ $i ] + 1, $this->notices( $id ), 'Every reporter hears back once.' );
		}
	}

	/** Item 15: removing the accepted answer unresolves the question; restoring it brings it back. */
	public function test_removed_accepted_answer_unresolves_the_question(): void {
		$space = $this->space( array( 'type' => 'qa' ) );
		$post  = $this->topic( $space, $this->author );
		$reply = $this->reply( $post, $this->reporter );
		$this->assertTrue( Reply::accept_as_answer( $reply, $this->author ) );
		$this->assertSame( (string) $reply, (string) Post::find( $post )->accepted_reply_id );

		Reply::update( $reply, array( 'status' => 'trash' ) );
		$row = Post::find( $post );
		$this->assertEmpty( $row->accepted_reply_id );
		$this->assertSame( '0', (string) $row->is_resolved );

		Reply::update( $reply, array( 'status' => 'publish' ) );
		$row = Post::find( $post );
		$this->assertSame( (string) $reply, (string) $row->accepted_reply_id );
		$this->assertSame( '1', (string) $row->is_resolved );

		Reply::delete( $reply );
		$row = Post::find( $post );
		$this->assertEmpty( $row->accepted_reply_id );
		$this->assertSame( '0', (string) $row->is_resolved );
	}

	/** Item 16: a private reply the viewer cannot read answers like a missing one. */
	public function test_unreadable_private_reply_answers_like_missing(): void {
		$post    = $this->topic( $this->space(), $this->author );
		$reply   = $this->reply( $post, $this->reporter, array( 'is_private' => 1 ) );
		$stranger = $this->user();

		$private = $this->rest( 'GET', "/replies/{$reply}", array(), $stranger );
		$missing = $this->rest( 'GET', '/replies/99999999', array(), $stranger );
		$this->assertSame( 404, $private->get_status() );
		$this->assertSame( $missing->get_data(), $private->get_data() );

		// Its author and the topic author still read it.
		$this->assertSame( 200, $this->rest( 'GET', "/replies/{$reply}", array(), $this->author )->get_status() );
	}

	/** Render one flag card as the per-space queue does. */
	private function card( int $flag_id, int $space_id ): string {
		$flag = Flag::find( $flag_id );
		$ctx  = Moderation_Service::flag_context( array( $flag ), \Jetonomy\base_url() );
		ob_start();
		\Jetonomy\Template_Loader::partial(
			'moderation/flag-card',
			array(
				'flag'             => $flag,
				'resolve_endpoint' => rest_url( 'jetonomy/v1/spaces/' . $space_id . '/moderation/flags/' ),
				'base'             => \Jetonomy\base_url(),
				'context'          => $ctx[ $flag->object_type . ':' . (int) $flag->object_id ] ?? null,
			)
		);
		return (string) ob_get_clean();
	}
}
