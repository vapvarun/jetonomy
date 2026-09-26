<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\UserProfile;

/**
 * The My Subscriptions badge / GET /subscriptions `via` reports the channel
 * the notifier will actually use, not the stored notify_via (Basecamp 10336124221).
 */
class SubscriptionDeliveryTest extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		do_action( 'rest_api_init' );
		$this->user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $this->user_id );
		update_option(
			'jetonomy_settings',
			array(
				'notification_defaults' => array(
					'new_post_in_sub' => array( 'web' => true, 'email' => false ),
					'reply_to_post'   => array( 'web' => true, 'email' => true ),
				),
			)
		);
		Subscription::subscribe( $this->user_id, 'space', 11 );
		Subscription::subscribe( $this->user_id, 'post', 22 );
	}

	private function vias(): array {
		wp_set_current_user( $this->user_id );
		$rows = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/subscriptions' ) )->get_data()['data'];
		return array_combine( wp_list_pluck( $rows, 'object_type' ), wp_list_pluck( $rows, 'via' ) );
	}

	public function test_via_follows_admin_defaults_not_stored_column(): void {
		$this->assertSame( array( 'post' => 'both', 'space' => 'web' ), $this->sorted( $this->vias() ) );
	}

	public function test_master_opt_out_drops_email_everywhere(): void {
		update_user_meta( $this->user_id, 'jetonomy_email_opt_out', '1' );
		$this->assertSame( array( 'post' => 'web', 'space' => 'web' ), $this->sorted( $this->vias() ) );
	}

	public function test_per_type_prefs_win_and_none_is_reported(): void {
		UserProfile::update_profile(
			$this->user_id,
			array( 'settings' => wp_json_encode( array( 'notifications' => array( 'reply_to_post' => array( 'web' => false, 'email' => false ) ) ) ) )
		);
		\Jetonomy\Cache::flush();
		$this->assertSame( 'none', $this->vias()['post'] );
	}

	public function test_template_and_rest_share_the_resolver(): void {
		$items = Subscription::attach_delivery(
			array(
				array( 'object_type' => 'space', 'object_id' => 11 ),
				array( 'object_type' => 'post', 'object_id' => 22 ),
			),
			$this->user_id
		);
		$this->assertSame( array( 'web', 'both' ), wp_list_pluck( $items, 'via' ) );
	}

	private function sorted( array $a ): array {
		ksort( $a );
		return $a;
	}
}
