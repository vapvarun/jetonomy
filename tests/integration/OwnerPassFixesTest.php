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
