<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Mentions;
use Jetonomy\Models\Notification;
use Jetonomy\Notifications\Notifier;
use Jetonomy\DB\Schema;

/**
 * The jetonomy_notification_should_send veto silences every notification row
 * AND every notification email in one filter.
 *
 * Importer seam (BuddyNext card 10124307318 companion): buddynext-importer
 * replays a source forum through the journeys, and each imported post/reply
 * would otherwise fan out subscriber + mention notifications and EMAILS per
 * row. Mirrors BuddyNext's buddynext_notification_should_send. Checked at
 * the two funnels everything flows through - Notification::create() (rows)
 * and Notifier::should_email() (emails) - plus Mentions::notify() so an
 * import skips the mention scan entirely.
 */
class NotificationVetoTest extends WP_UnitTestCase {

	private int $recipient;

	private int $actor;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->recipient = self::factory()->user->create();
		$this->actor     = self::factory()->user->create();
	}

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_notification_should_send' );
		parent::tear_down();
	}

	public function test_veto_blocks_notification_rows(): void {
		add_filter( 'jetonomy_notification_should_send', '__return_false' );

		$id = Notification::create(
			[
				'user_id'     => $this->recipient,
				'actor_id'    => $this->actor,
				'type'        => 'mention',
				'object_type' => 'post',
				'object_id'   => 1,
				'message'     => 'Vetoed',
			]
		);

		$this->assertSame( 0, $id, 'a vetoed notification must not create a row' );
	}

	public function test_veto_blocks_email(): void {
		add_filter( 'jetonomy_notification_should_send', '__return_false' );

		$this->assertFalse( Notifier::should_email( $this->recipient, 'mention' ) );
	}

	public function test_veto_blocks_mentions_entirely(): void {
		global $wpdb;

		add_filter( 'jetonomy_notification_should_send', '__return_false' );

		Mentions::notify( [ $this->recipient ], $this->actor, 'post', 1, 'Imported topic' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_notifications WHERE user_id = %d", $this->recipient )
		);
		$this->assertSame( 0, $count, 'imported content must not fan out mention notifications' );
	}

	public function test_default_behaviour_unchanged(): void {
		$id = Notification::create(
			[
				'user_id'     => $this->recipient,
				'actor_id'    => $this->actor,
				'type'        => 'mention',
				'object_type' => 'post',
				'object_id'   => 1,
				'message'     => 'Live',
			]
		);

		$this->assertGreaterThan( 0, $id, 'without the veto, notifications create as before' );
		$this->assertTrue( Notifier::should_email( $this->recipient, '' ), 'kill-switch-only email check still passes by default' );
	}
}
