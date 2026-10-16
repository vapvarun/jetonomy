<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Flag;
use Jetonomy\Notifications\Notifier;
use Jetonomy\Moderation\Moderation_Service;

/**
 * Fixes from the 2026-10-16 owner-first QA pass (Possible bugs column).
 * Each test states what the owner or member expects, and fails on the code
 * the pass found.
 */
class OwnerPassFixesTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin;
	private int $member;
	private int $cat;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		reset_phpmailer_instance();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'member-' . uniqid() . '@example.test',
			)
		);
		$this->cat    = (int) Category::create(
			array(
				'name' => 'Owner pass',
				'slug' => 'owner-pass-' . uniqid(),
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( 'jetonomy_email_templates' );
		parent::tear_down();
	}

	private function space( array $over = array() ): int {
		return (int) Space::create(
			array_merge(
				array(
					'title'       => 'Owner pass space',
					'slug'        => 'op-' . uniqid(),
					'category_id' => $this->cat,
					'visibility'  => 'private',
					'join_policy' => 'approval',
					'type'        => 'forum',
				),
				$over
			),
			$this->admin
		);
	}

	private function rest( string $method, string $route ): \WP_REST_Response {
		return $this->server->dispatch( new WP_REST_Request( $method, '/jetonomy/v1' . $route ) );
	}

	/** @return string[] Subjects of mail sent to $email. */
	private function subjects_to( string $email ): array {
		$out = array();
		foreach ( (array) tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			foreach ( (array) $mail['to'] as $to ) {
				if ( $email === $to[0] ) {
					$out[] = (string) $mail['subject'];
				}
			}
		}
		return $out;
	}

	public function test_a_member_is_emailed_when_their_join_request_is_answered(): void {
		$space = $this->space();
		SpaceMember::join( $space, $this->member );
		$jr = JoinRequest::find_pending( $space, $this->member );

		$this->assertTrue( Notifier::default_email( 'join_request_result', array( 'reply_to_post' => array( 'email' => true ) ) ), 'on for sites seeded before the type existed' );
		$this->assertTrue( JoinRequest::approve( (int) $jr->id, $this->admin ) );
		$this->assertNotEmpty( $this->subjects_to( get_userdata( $this->member )->user_email ), 'the answer reaches the member by email' );
	}

	public function test_the_join_answer_email_heading_reads_like_settings_not_an_internal_label(): void {
		$space = $this->space();
		SpaceMember::join( $space, $this->member );
		JoinRequest::approve( (int) JoinRequest::find_pending( $space, $this->member )->id, $this->admin );

		$to   = get_userdata( $this->member )->user_email;
		$body = '';
		foreach ( (array) tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			if ( $to === $mail['to'][0][0] ) {
				$body = (string) $mail['body'];
			}
		}
		$this->assertStringContainsString( 'Your join request was answered', $body, 'the heading the owner sees in Settings > Emails' );
		$this->assertStringNotContainsString( 'Join Request Result', $body );
	}

	public function test_an_email_login_members_profile_link_resolves_and_never_shows_the_email(): void {
		$id   = self::factory()->user->create( array( 'user_login' => 'lee@example.test' ) );
		$user = get_userdata( $id );
		$url  = \Jetonomy\community_profile_url( $id );

		$this->assertStringNotContainsString( 'example.test/', str_replace( home_url(), '', $url ), 'the login is not in the link' );
		// WP_MatchesMapRegex urlencodes the match, parse_str decodes it once: the slug that arrives.
		parse_str( 'slug=' . urlencode( rtrim( basename( $url ), '/' ) ), $q );
		$this->assertSame( $id, \Jetonomy\profile_user( $q['slug'] )->ID ?? 0, 'the link opens the profile' );
		$this->assertSame( $id, \Jetonomy\profile_user( 'lee@example.test' )->ID ?? 0, 'links shared with the login still open' );
		$this->assertNull( \Jetonomy\profile_user( 'nobody-' . uniqid() ) );
		$this->assertStringNotContainsString( '@example.test', \Jetonomy\user_handle( $user ), 'the public handle is not the email' );
	}

	public function test_list_pagination_never_links_with_the_wordpress_paged_param(): void {
		ob_start();
		\Jetonomy\Template_Loader::partial(
			'pagination-nav',
			array(
				'pages' => 3,
				'paged' => 2,
				'base'  => \Jetonomy\route_url( 'moderation' ),
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'pg=1', $html );
		$this->assertStringContainsString( 'pg=3', $html );
		$this->assertStringNotContainsString( 'paged=', $html, 'sites that rewrite ?paged to /page/N/ turned Next into a 404' );

		$_GET = array( 'pg' => '4' );
		$this->assertSame( 4, \Jetonomy\list_page() );
		$_GET = array( 'paged' => '3' );
		$this->assertSame( 3, \Jetonomy\list_page(), 'links shared before the change still open their page' );
		$_GET = array();
		$this->assertSame( 1, \Jetonomy\list_page() );
	}

	public function test_public_rest_payloads_never_carry_an_email_login(): void {
		$author = self::factory()->user->create(
			array(
				'user_login'   => 'kim@example.test',
				'display_name' => 'Kim',
			)
		);
		$space  = $this->space(
			array(
				'visibility'  => 'public',
				'join_policy' => 'open',
			)
		);
		SpaceMember::add( $space, $author, 'member' );
		$post = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $author,
				'title'     => 'Email login topic',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);
		\Jetonomy\Models\Reply::create(
			array(
				'post_id'   => $post,
				'space_id'  => $space,
				'author_id' => $author,
				'content'   => 'A reply',
				'status'    => 'publish',
			)
		);

		wp_set_current_user( 0 );
		foreach ( array( "/posts/{$post}", "/spaces/{$space}/posts", "/posts/{$post}/replies" ) as $route ) {
			$res = $this->rest( 'GET', $route );
			$this->assertSame( 200, $res->get_status(), $route );
			$this->assertStringNotContainsString( 'kim@example.test', (string) wp_json_encode( $res->get_data() ), $route . ' leaks the login' );
		}
		$this->assertSame( \Jetonomy\user_handle( $author ), $this->rest( 'GET', "/posts/{$post}" )->get_data()['author_login'], 'the field still carries the public handle' );
	}

	public function test_the_removed_content_email_gives_the_author_no_mod_queue_button(): void {
		$space = $this->space(
			array(
				'visibility'  => 'public',
				'join_policy' => 'open',
			)
		);
		$post  = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $this->member,
				'title'     => 'Removed topic',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);
		( new Notifier() )->on_content_moderated( 'trash', 'post', $post, $this->admin );

		$to   = get_userdata( $this->member )->user_email;
		$body = '';
		foreach ( (array) tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			if ( $to === $mail['to'][0][0] ) {
				$body = (string) $mail['body'];
			}
		}
		$this->assertStringContainsString( 'Open the Community', $body );
		$this->assertStringNotContainsString( 'Review in Mod Queue', $body, 'the author has no mod queue' );
		$this->assertStringContainsString( 'Your ' . \Jetonomy\jetonomy_label( 'topic', false, true ) . ' was removed', $body );
	}

	public function test_the_owners_mention_subject_is_used(): void {
		update_option(
			'jetonomy_email_templates',
			array(
				'mention' => array(
					'subject' => 'OWNER-SUBJECT {site}',
					'body'    => '',
				),
			)
		);
		Notifier::send_email_notification( $this->member, 'mention', 'Someone mentioned you', 'post', 1, 'https://example.test/t/1/' );
		$subjects = $this->subjects_to( get_userdata( $this->member )->user_email );
		$this->assertNotEmpty( $subjects );
		$this->assertStringStartsWith( 'OWNER-SUBJECT ', $subjects[0] );
	}

	public function test_the_verification_email_takes_the_owners_subject_and_keeps_the_link(): void {
		update_option(
			'jetonomy_email_templates',
			array(
				'verification' => array(
					'subject' => 'Welcome aboard {site}',
					'body'    => 'Hello from {site}.',
				),
			)
		);
		Notifier::send_verification_email( $this->member, 'tok123' );
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringStartsWith( 'Welcome aboard ', (string) $sent->subject );
		$this->assertStringContainsString( 'verify-email', (string) $sent->body, 'the confirmation link is always there' );
	}

	public function test_a_member_can_withdraw_their_own_pending_request_and_staff_notices_go(): void {
		$space = $this->space();
		SpaceMember::join( $space, $this->member );
		$this->assertNotNull( JoinRequest::find_pending( $space, $this->member ) );

		wp_set_current_user( $this->member );
		$res = $this->rest( 'DELETE', "/spaces/{$space}/join-requests/mine" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( JoinRequest::find_pending( $space, $this->member ) );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Jetonomy\table( 'notifications' ) . " WHERE type = 'join_request' AND object_id = %d AND actor_id = %d", $space, $this->member ) ), 'the staff notices go with it' );
		$this->assertSame( 404, $this->rest( 'DELETE', "/spaces/{$space}/join-requests/mine" )->get_status(), 'nothing left to withdraw' );
	}

	public function test_taking_back_the_only_vote_removes_the_grouped_notice(): void {
		$space  = $this->space(
			array(
				'visibility'  => 'public',
				'join_policy' => 'open',
			)
		);
		$author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$voter  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		SpaceMember::add( $space, $author, 'member' );
		SpaceMember::add( $space, $voter, 'member' );
		$post = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $author,
				'title'     => 'Vote me',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		wp_set_current_user( $voter );
		$vote = static function ( WP_REST_Server $server, int $id ): void {
			$req = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$id}/vote" );
			$req->set_param( 'value', 1 );
			$server->dispatch( $req );
		};
		$vote( $this->server, $post );
		$vote( $this->server, $post ); // Same upvote again: taken back.

		global $wpdb;
		$left = $wpdb->get_col( $wpdb->prepare( 'SELECT message FROM ' . \Jetonomy\table( 'notifications' ) . " WHERE user_id = %d AND type = 'vote_on_post'", $author ) );
		foreach ( $left as $message ) {
			$this->assertStringNotContainsString( '0 people', (string) $message );
		}
		$this->assertCount( 0, $left, 'no vote is left, so no "voted on" notice is either' );
	}

	public function test_a_per_space_flag_names_its_topic(): void {
		$space = $this->space(
			array(
				'visibility'  => 'public',
				'join_policy' => 'open',
			)
		);
		$post  = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $this->admin,
				'title'     => 'Flagged topic title',
				'content'   => 'Flagged body text',
				'status'    => 'publish',
			)
		);
		$flag  = Flag::find(
			(int) Flag::create(
				array(
					'reporter_id' => $this->member,
					'object_type' => 'post',
					'object_id'   => $post,
					'reason'      => 'spam',
				)
			)
		);
		$ctx   = Moderation_Service::flag_context( array( $flag ), \Jetonomy\base_url() );
		$this->assertSame( 'Flagged topic title', $ctx[ 'post:' . $post ]->topic->title );

		ob_start();
		\Jetonomy\Template_Loader::partial(
			'moderation/flag-card',
			array(
				'flag'             => $flag,
				'resolve_endpoint' => '/jetonomy/v1/spaces/' . $space . '/moderation/flags/' . (int) $flag->id . '/resolve',
				'base'             => \Jetonomy\base_url(),
				'context'          => $ctx[ 'post:' . $post ],
			)
		);
		$this->assertStringContainsString( 'Flagged topic title', (string) ob_get_clean() );
	}

	public function test_a_messenger_can_supply_the_profile_message_link(): void {
		$member = get_userdata( $this->member );
		$this->assertSame( '', \Jetonomy\member_message_url( $member ), 'no messenger, no button' );
		add_filter( 'jetonomy_member_message_url', static fn( $url, $who ) => 'https://example.test/messages/?to=' . $who->ID, 10, 2 );
		$this->assertSame( 'https://example.test/messages/?to=' . $this->member, \Jetonomy\member_message_url( $member ) );
	}

	public function test_admin_headings_take_the_white_label_brand_name(): void {
		$this->assertSame( 'Jetonomy', \Jetonomy\Admin\Admin::brand_name() );
		add_filter( 'jetonomy_brand_name', static fn() => 'Acme Hub' );
		$this->assertSame( 'Acme Hub', \Jetonomy\Admin\Admin::brand_name() );
	}

}
