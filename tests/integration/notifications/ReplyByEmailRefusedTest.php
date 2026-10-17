<?php
/**
 * An emailed reply that is refused gets one email back saying why.
 *
 * The inbound webhook answers "handled" so the provider does not retry, and
 * Notifier::on_reply_from_email() returned silently on refusal, so the member's
 * reply just vanished.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Integration\Notifications;

use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use WP_UnitTestCase;

class ReplyByEmailRefusedTest extends WP_UnitTestCase {

	private int $topic;
	private int $member;
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$cat          = Category::create( array( 'name' => 'Announcements', 'slug' => 'ann-' . uniqid() ) );
		$space        = Space::create( array( 'title' => 'Announcements', 'slug' => 'announcements-' . uniqid(), 'category_id' => $cat, 'visibility' => 'public', 'join_policy' => 'open' ) );
		$this->topic  = (int) Post::create( array( 'space_id' => $space, 'author_id' => 1, 'title' => 'Scheduled maintenance on Saturday', 'content' => '<p>Read-only for an hour.</p>', 'status' => 'publish' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'maya@example.com' ) );
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

	private function email_in( string $text ): void {
		do_action( 'jetonomy_create_reply_from_email', $this->topic, $this->member, $text, 'reply_by_email' );
	}

	public function test_a_reply_to_a_closed_topic_is_answered_with_the_reason(): void {
		Post::update( $this->topic, array( 'is_closed' => 1 ) );
		\Jetonomy\Cache::flush();

		$this->email_in( '<p>Will the mobile app be down too?</p>' );

		$this->assertSame( 0, (int) Reply::count_by_post( $this->topic ), 'Nothing may be posted to a closed topic' );
		$this->assertCount( 1, $this->sent, 'The sender must be told their reply was not posted' );
		$this->assertSame( 'maya@example.com', is_array( $this->sent[0]['to'] ) ? $this->sent[0]['to'][0] : $this->sent[0]['to'] );
		$this->assertStringContainsString( 'closed', $this->sent[0]['message'], 'The email must give the reason' );
	}

	/**
	 * @covers-existing An accepted reply sends no refusal.
	 */
	public function test_an_accepted_reply_sends_no_refusal(): void {
		$this->email_in( '<p>Thanks for the heads-up.</p>' );

		$this->assertSame( 1, (int) Reply::count_by_post( $this->topic ) );
		foreach ( $this->sent as $mail ) {
			$this->assertStringNotContainsString( 'not posted', (string) $mail['subject'] );
		}
	}
}
