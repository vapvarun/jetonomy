<?php
/**
 * Notification wording and delivery, as a member reads it (2.0.3 walk).
 *
 * Every email names its actor and space, titles are shortened at a word
 * boundary, followers and staff get their own wording, the grouped vote notice
 * has no stray quotes, someone else's notification answers 404, and a member
 * who can no longer read the content gets no notice of it at all.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Notifications;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Vote;
use Jetonomy\Notifications\Notifier;
use WP_REST_Request;
use WP_UnitTestCase;

class NotificationDeliveryFixesTest extends WP_UnitTestCase {

	private const LONG_TITLE = 'What is the recommended way to handle knowledge base articles across teams';

	private int $space;
	private int $bob;
	private int $alice;
	private int $maya;
	private array $sent = array();
	private string $u;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		// Email on for every type, as a site that seeded its defaults has it.
		$defaults = array();
		foreach ( array_keys( \Jetonomy\Admin\Admin::email_types() ) as $type ) {
			$defaults[ $type ] = array(
				'web'   => true,
				'email' => true,
			);
		}
		update_option( 'jetonomy_settings', array( 'notification_defaults' => $defaults ) );
		delete_option( 'jetonomy_email_templates' );

		$cat         = Category::create( array( 'name' => 'Help', 'slug' => 'help-' . uniqid() ) );
		$this->space = (int) Space::create( array( 'title' => 'Help Desk', 'slug' => 'help-desk-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public', 'join_policy' => 'open' ) );
		// Unique per test: the schema DDL above commits, so users outlive the test.
		$this->u     = substr( uniqid(), -6 );
		$this->bob   = self::factory()->user->create( array( 'display_name' => 'Bob Martinez', 'user_login' => 'bobm' . $this->u, 'user_email' => "bob{$this->u}@example.com" ) );
		$this->alice = self::factory()->user->create( array( 'display_name' => 'Alice Chen', 'user_login' => 'alicec' . $this->u, 'user_email' => "alice{$this->u}@example.com" ) );
		$this->maya  = self::factory()->user->create( array( 'display_name' => 'Maya Singh', 'user_login' => 'mayas' . $this->u, 'user_email' => "maya{$this->u}@example.com" ) );

		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $short, array $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	/** Emails sent to one address. */
	private function mail_to( string $email ): array {
		return array_values(
			array_filter(
				$this->sent,
				static fn( array $m ): bool => in_array( $email, (array) $m['to'], true )
			)
		);
	}

	private function topic( int $author, string $title = 'Where do I find my drafts?', int $space = 0 ): int {
		return (int) Post::create( array( 'space_id' => $space ?: $this->space, 'author_id' => $author, 'title' => $title, 'content' => '<p>Question body.</p>', 'status' => 'publish' ) );
	}

	private function reply( int $topic, int $author, string $content = '<p>Thanks for asking, here is how.</p>' ): int {
		$id = (int) Reply::create( array( 'post_id' => $topic, 'author_id' => $author, 'content' => $content ) );
		do_action( 'jetonomy_after_create_reply', $id, $topic, null );
		return $id;
	}

	private function rows( int $user_id, string $type ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_notifications WHERE user_id = %d AND type = %s", $user_id, $type ) );
	}

	// Item 1: the mention email names who mentioned you.
	public function test_mention_email_subject_names_the_actor(): void {
		$topic = $this->topic( $this->alice );
		$this->reply( $topic, $this->bob, "<p>@mayas{$this->u} could you take a look?</p>" );

		$mail = $this->mail_to( "maya{$this->u}@example.com" );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'You were mentioned by Bob Martinez', $mail[0]['subject'] );
	}

	// Item 1: the new-topic email names the space.
	public function test_new_topic_email_subject_names_the_space(): void {
		Subscription::subscribe( $this->maya, 'space', $this->space );
		$topic = $this->topic( $this->bob );
		do_action( 'jetonomy_after_create_post', $topic, $this->space );

		$mail = $this->mail_to( "maya{$this->u}@example.com" );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'in Help Desk', $mail[0]['subject'] );
	}

	// Item 3: a long title is cut at a word with an ellipsis, in the notice and the email.
	public function test_long_title_is_cut_at_a_word_with_an_ellipsis(): void {
		$topic = $this->topic( $this->bob, self::LONG_TITLE );
		$this->reply( $topic, $this->alice );

		$row = $this->rows( $this->bob, 'reply_to_post' );
		$this->assertCount( 1, $row );
		$this->assertStringNotContainsString( 'knowledge ba"', $row[0]->message );
		$this->assertStringContainsString( 'knowledge…"', $row[0]->message );

		$mail = $this->mail_to( "bob{$this->u}@example.com" );
		$this->assertCount( 1, $mail );
		$this->assertStringNotContainsString( 'knowledge ba"', $mail[0]['message'] );
	}

	// Item 4: the staff alert reads as a new report, not as the author's email.
	public function test_staff_flag_alert_reads_as_a_report_to_review(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => "staff{$this->u}@example.com" ) );
		$topic = $this->topic( $this->bob );
		$flag  = Flag::create( array( 'reporter_id' => $this->alice, 'object_type' => 'post', 'object_id' => $topic, 'reason' => 'spam' ) );
		do_action( 'jetonomy_flag_created', $flag, 'post' );

		$mail = $this->mail_to( "staff{$this->u}@example.com" );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'New report needs review', $mail[0]['subject'] );
		$this->assertStringNotContainsString( 'A moderator reviewed your content', $mail[0]['subject'] );
		$this->assertStringNotContainsString( 'reply to a moderator', $mail[0]['message'] );
		$this->assertNotEmpty( $this->rows( $admin, 'moderation' ) );
	}

	// Item 5: a follower is not told it was their topic; the author still is.
	public function test_follower_email_is_worded_for_a_follower(): void {
		$topic = $this->topic( $this->bob );
		Subscription::subscribe( $this->maya, 'post', $topic );
		$this->reply( $topic, $this->alice );

		$follower = $this->mail_to( "maya{$this->u}@example.com" );
		$this->assertCount( 1, $follower );
		$this->assertStringNotContainsString( 'replied to your', $follower[0]['subject'] );
		$this->assertStringContainsString( 'Alice Chen replied in Where do I find my drafts?', $follower[0]['subject'] );

		$author = $this->mail_to( "bob{$this->u}@example.com" );
		$this->assertCount( 1, $author );
		$this->assertStringContainsString( 'Alice Chen replied to your', $author[0]['subject'] );
	}

	// Item 11: "2 people voted on your reply", no quotes around "your reply".
	public function test_grouped_vote_notice_has_no_quotes_around_your_reply(): void {
		$topic = $this->topic( $this->bob );
		$reply = (int) Reply::create( array( 'post_id' => $topic, 'author_id' => $this->alice, 'content' => '<p>Answer.</p>' ) );

		Vote::cast( $this->bob, 'reply', $reply, 1 );
		do_action( 'jetonomy_after_vote', 'reply', $reply, $this->bob, 1 );
		Vote::cast( $this->maya, 'reply', $reply, 1 );
		do_action( 'jetonomy_after_vote', 'reply', $reply, $this->maya, 1 );

		$rows = $this->rows( $this->alice, 'vote_on_post' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2 people voted on your reply', $rows[0]->message );
	}

	// Item 12: a topic author who lost access to a private space hears nothing more of it.
	public function test_no_notice_of_content_the_recipient_can_no_longer_read(): void {
		$private = (int) Space::create( array( 'title' => 'Print Lab Circle', 'slug' => 'print-lab-' . uniqid(), 'category_id' => Category::create( array( 'name' => 'P', 'slug' => 'p-' . uniqid() ) ), 'visibility' => 'private', 'join_policy' => 'invite' ) );
		SpaceMember::add( $private, $this->bob, 'member' );
		SpaceMember::add( $private, $this->alice, 'member' );
		$topic = $this->topic( $this->bob, 'Mounting tape or corners for prints?', $private );
		Subscription::subscribe( $this->maya, 'post', $topic );

		SpaceMember::remove( $private, $this->bob );
		\Jetonomy\Cache::flush();

		$this->reply( $topic, $this->alice, '<p>Corners, always.</p>' );

		$this->assertSame( array(), $this->rows( $this->bob, 'reply_to_post' ) );
		$this->assertSame( array(), $this->mail_to( "bob{$this->u}@example.com" ) );
		$this->assertSame( array(), $this->mail_to( "maya{$this->u}@example.com" ), 'A follower who was never a member hears nothing either' );
	}

	/** @covers-existing A member who can still read the topic is told as before. */
	public function test_a_member_who_can_read_is_still_notified(): void {
		$topic = $this->topic( $this->bob );
		$this->reply( $topic, $this->alice );
		$this->assertCount( 1, $this->rows( $this->bob, 'reply_to_post' ) );
	}

	// Item 9: someone else's notification answers exactly like a missing one.
	public function test_patch_on_someone_elses_notification_answers_404(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$theirs = (int) Notification::create( array( 'user_id' => $this->bob, 'actor_id' => $this->maya, 'type' => 'mention', 'object_type' => 'post', 'object_id' => 1, 'message' => 'x' ) );
		wp_set_current_user( $this->alice );

		$other   = rest_do_request( new WP_REST_Request( 'PATCH', '/jetonomy/v1/notifications/' . $theirs ) );
		$missing = rest_do_request( new WP_REST_Request( 'PATCH', '/jetonomy/v1/notifications/' . ( $theirs + 999 ) ) );

		$this->assertSame( 404, $other->get_status() );
		$this->assertSame( $missing->get_status(), $other->get_status() );
		$this->assertSame( $missing->get_data()['code'] ?? '', $other->get_data()['code'] ?? '' );
		$this->assertSame( '0', (string) Notification::find( $theirs )->is_read );
		$wp_rest_server = null;
	}

	// Item 2: every default template's placeholders are listed and filled by Send test.
	public function test_every_placeholder_a_default_template_uses_is_listed_and_filled_in_samples(): void {
		$extra = ( new \ReflectionMethod( \Jetonomy\Admin\Ajax\Settings_Handler::class, 'sample_extra' ) )->invoke( null );
		$user  = get_userdata( $this->bob );
		foreach ( array_keys( \Jetonomy\Admin\Admin::email_types() ) as $type ) {
			$tpl = Notifier::get_default_template( $type );
			preg_match_all( '/\{[a-z_]+\}/', $tpl['subject'] . $tpl['body'], $m );
			foreach ( $m[0] as $token ) {
				$this->assertContains( $token, Notifier::EMAIL_PLACEHOLDERS, "{$type} uses {$token}, which Settings > Email does not list" );
			}
			$subject = strtr( $tpl['subject'], Notifier::email_placeholders( $user, $type, 'msg', 'https://example.com', $extra ) );
			$this->assertStringNotContainsString( '  ', $subject, "{$type} test subject has a blank placeholder" );
			$this->assertDoesNotMatchRegularExpression( '/ (by|in)$/', $subject, "{$type} test subject ends on a blank placeholder" );
		}
	}

	/** Shared helper: cut at a word, add an ellipsis, leave short text alone. */
	public function test_short_title(): void {
		$this->assertSame( 'What is the recommended way to handle knowledge…', \Jetonomy\short_title( self::LONG_TITLE ) );
		$this->assertSame( 'Short title', \Jetonomy\short_title( 'Short title' ) );
		$this->assertSame( 'تجربتي مع مجموعات التصوير المحلية في…', \Jetonomy\short_title( 'تجربتي مع مجموعات التصوير المحلية في المدينة القديمة', 40 ) );
	}
}
