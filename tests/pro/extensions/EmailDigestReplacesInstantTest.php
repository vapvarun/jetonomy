<?php
/**
 * A chosen daily or weekly digest replaces the instant emails it covers, the
 * member can choose it in their profile's notification settings, and the
 * digest never lists replies from a space the member can no longer read.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy_Pro\Extensions\Email_Digest\Extension;

class EmailDigestReplacesInstantTest extends WP_UnitTestCase {

	private Extension $ext;
	private int $space;
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Extension::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		update_option(
			'jetonomy_settings',
			array(
				'notification_defaults' => array(
					'reply_to_post' => array(
						'web'   => true,
						'email' => true,
					),
				),
			)
		);
		delete_option( 'jetonomy_pro_digest_settings' );

		$this->ext = new Extension();
		if ( ! has_filter( 'jetonomy_send_instant_email', array( $this->ext, 'filter_instant_email' ) ) ) {
			add_filter( 'jetonomy_send_instant_email', array( $this->ext, 'filter_instant_email' ), 10, 3 );
		}
		$this->space = (int) Space::create( array( 'title' => 'Digest', 'slug' => 'dg-' . uniqid(), 'category_id' => Category::create( array( 'name' => 'D', 'slug' => 'dg-c-' . uniqid() ) ), 'visibility' => 'public', 'join_policy' => 'open' ) );
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ), 10 );
		remove_filter( 'jetonomy_send_instant_email', array( $this->ext, 'filter_instant_email' ), 10 );
		parent::tear_down();
	}

	public function capture( $short, array $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	private function reply_to_topic_of( int $author, int $space = 0 ): int {
		$topic = (int) Post::create( array( 'space_id' => $space ?: $this->space, 'author_id' => $author, 'title' => 'Digest topic', 'content' => '<p>T</p>', 'status' => 'publish' ) );
		$reply = (int) Reply::create( array( 'post_id' => $topic, 'author_id' => self::factory()->user->create(), 'content' => '<p>Answer text for the digest.</p>', 'content_plain' => 'Answer text for the digest.' ) );
		do_action( 'jetonomy_after_create_reply', $reply, $topic, null );
		return $topic;
	}

	private function emails_to( int $user_id ): int {
		$email = get_userdata( $user_id )->user_email;
		return count( array_filter( $this->sent, static fn( array $m ): bool => in_array( $email, (array) $m['to'], true ) ) );
	}

	public function test_a_chosen_daily_digest_replaces_the_instant_reply_email(): void {
		$bob = self::factory()->user->create();
		update_user_meta( $bob, 'jetonomy_pro_digest_frequency', 'daily' );

		$this->reply_to_topic_of( $bob );

		$this->assertSame( 0, $this->emails_to( $bob ) );
	}

	/** @covers-existing No digest chosen: the instant email still goes out. */
	public function test_no_digest_keeps_the_instant_email(): void {
		$bob = self::factory()->user->create();
		update_user_meta( $bob, 'jetonomy_pro_digest_frequency', 'none' );

		$this->reply_to_topic_of( $bob );

		$this->assertSame( 1, $this->emails_to( $bob ) );
	}

	public function test_the_profile_notification_settings_offer_the_digest_choice(): void {
		$bob = self::factory()->user->create();
		update_user_meta( $bob, 'jetonomy_pro_digest_frequency', 'weekly' );

		ob_start();
		do_action( 'jetonomy_profile_notification_fields', $bob );
		if ( ! has_action( 'jetonomy_profile_notification_fields', array( $this->ext, 'render_profile_choice' ) ) ) {
			$this->ext->render_profile_choice( $bob );
		}
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="digest-preferences"', $html );
		$this->assertMatchesRegularExpression( '/name="digest_frequency" value="weekly"\s+checked/', $html );
		$this->assertStringContainsString( '#digest-preferences', \Jetonomy\get_profile_action_url( 'digest', $bob ) );
		$this->assertStringContainsString( \Jetonomy\route_url( 'edit-profile', get_userdata( $bob )->user_nicename ), \Jetonomy\get_profile_action_url( 'digest', $bob ), 'The unsubscribe page sends members to the edit page' );
	}

	public function test_the_digest_leaves_out_replies_from_a_space_the_member_left(): void {
		$bob     = self::factory()->user->create();
		$private = (int) Space::create( array( 'title' => 'Private', 'slug' => 'dg-p-' . uniqid(), 'category_id' => Category::create( array( 'name' => 'P', 'slug' => 'dg-pc-' . uniqid() ) ), 'visibility' => 'private', 'join_policy' => 'invite' ) );
		SpaceMember::add( $private, $bob, 'member' );
		$topic = $this->reply_to_topic_of( $bob, $private );
		SpaceMember::remove( $private, $bob );
		\Jetonomy\Cache::flush();

		$digest = $this->ext->compile_digest( $bob, 'daily' );

		$this->assertNotContains( $topic, array_map( static fn( $r ) => (int) $r->post_id, $digest['replies_to_user'] ) );
	}
}
