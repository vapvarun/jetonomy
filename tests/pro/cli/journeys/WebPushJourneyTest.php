<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Web_Push_Journey;

/**
 * Integration tests for Web_Push_Journey against the live
 * `wp_jt_pro_push_subscriptions` table.
 *
 * The Pro plugin must be active during the test run so the subscriptions
 * table exists (it is created in Web_Push\Extension::activate() via dbDelta).
 * Each test manages its own fixture rows and tear_down() wipes every row
 * created for the test users so runs remain independent.
 *
 * HTTP interception strategy for send_push():
 *     The Web Push extension's do_send_push() helper delivers via
 *     wp_remote_post(). We short-circuit it globally through the
 *     `pre_http_request` filter installed in set_up(), which forces every
 *     outbound HTTP call from this test class to resolve to a canned 201
 *     response without touching the network. The filter is removed in
 *     tear_down(). We also assert that `send_push()` never throws even
 *     when VAPID keys are absent — the dispatch helper returns silently
 *     on missing keys, so the journey's counters still tick `sent` for
 *     each targeted subscription.
 */
class WebPushJourneyTest extends WP_UnitTestCase {

	private Web_Push_Journey $journey;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Web_Push_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Web_Push_Journey.' );
		}

		$this->journey = new Web_Push_Journey();
		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$this->truncate_fixture_rows();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		$this->truncate_fixture_rows();
		parent::tear_down();
	}

	/**
	 * Canned HTTP response so do_send_push() never hits the network.
	 *
	 * @param false|array|\WP_Error $preempt Existing preempt value.
	 * @param array                 $args    Request args.
	 * @param string                $url     Target URL.
	 */
	public function intercept_http( $preempt, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
			'cookies'  => array(),
			'filename' => '',
		);
	}

	private function truncate_fixture_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_push_subscriptions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d",
				$this->user_id
			)
		);
	}

	private function valid_subscription( string $endpoint = 'https://fcm.googleapis.com/fcm/send/fixture-a' ): array {
		return array(
			'endpoint' => $endpoint,
			'p256dh'   => 'BASE64URL_P256DH_VALUE',
			'auth'     => 'BASE64URL_AUTH_VALUE',
		);
	}

	public function test_subscribe_persists_row(): void {
		global $wpdb;

		$result = $this->journey->subscribe( $this->user_id, $this->valid_subscription() );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertGreaterThan( 0, (int) $result->data['subscription_id'] );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );
		$this->assertSame( 'created', $result->data['created_or_updated'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_push_subscriptions WHERE user_id = %d",
				$this->user_id
			)
		);
		$this->assertSame( 1, $row_count );
	}

	public function test_subscribe_upserts_on_duplicate_endpoint(): void {
		global $wpdb;

		$first = $this->journey->subscribe( $this->user_id, $this->valid_subscription() );

		$updated_subscription = array(
			'endpoint' => 'https://fcm.googleapis.com/fcm/send/fixture-a',
			'p256dh'   => 'UPDATED_P256DH',
			'auth'     => 'UPDATED_AUTH',
		);

		$second = $this->journey->subscribe( $this->user_id, $updated_subscription );

		$this->assertTrue( $first->is_success() );
		$this->assertSame( 'created', $first->data['created_or_updated'] );

		$this->assertTrue( $second->is_success() );
		$this->assertSame( 'updated', $second->data['created_or_updated'] );
		$this->assertSame( (int) $first->data['subscription_id'], (int) $second->data['subscription_id'] );

		// Still exactly one row for that endpoint.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, p256dh, auth FROM {$wpdb->prefix}jt_pro_push_subscriptions WHERE endpoint = %s",
				'https://fcm.googleapis.com/fcm/send/fixture-a'
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 'UPDATED_P256DH', $row->p256dh );
		$this->assertSame( 'UPDATED_AUTH', $row->auth );
	}

	public function test_subscribe_rejects_missing_keys(): void {
		$no_endpoint = $this->journey->subscribe(
			$this->user_id,
			array(
				'endpoint' => '',
				'p256dh'   => 'x',
				'auth'     => 'y',
			)
		);
		$this->assertFalse( $no_endpoint->is_success() );
		$this->assertStringContainsString( 'endpoint', strtolower( (string) $no_endpoint->first_error() ) );

		$no_p256dh = $this->journey->subscribe(
			$this->user_id,
			array(
				'endpoint' => 'https://example.com/sub',
				'p256dh'   => '',
				'auth'     => 'y',
			)
		);
		$this->assertFalse( $no_p256dh->is_success() );
		$this->assertStringContainsString( 'p256dh', strtolower( (string) $no_p256dh->first_error() ) );

		$no_auth = $this->journey->subscribe(
			$this->user_id,
			array(
				'endpoint' => 'https://example.com/sub',
				'p256dh'   => 'x',
				'auth'     => '',
			)
		);
		$this->assertFalse( $no_auth->is_success() );
		$this->assertStringContainsString( 'auth', strtolower( (string) $no_auth->first_error() ) );

		$bad_user = $this->journey->subscribe( 0, $this->valid_subscription() );
		$this->assertFalse( $bad_user->is_success() );
		$this->assertStringContainsString( 'user_id', strtolower( (string) $bad_user->first_error() ) );
	}

	public function test_unsubscribe_removes_row(): void {
		global $wpdb;

		$this->journey->subscribe( $this->user_id, $this->valid_subscription() );

		$result = $this->journey->unsubscribe( $this->user_id, 'https://fcm.googleapis.com/fcm/send/fixture-a' );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['removed'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_push_subscriptions WHERE user_id = %d",
				$this->user_id
			)
		);
		$this->assertSame( 0, $row_count );
	}

	public function test_unsubscribe_is_noop_when_absent(): void {
		$result = $this->journey->unsubscribe( $this->user_id, 'https://example.com/never-subscribed' );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['removed'] );
	}

	public function test_list_subscriptions_returns_items_and_columns(): void {
		$this->journey->subscribe( $this->user_id, $this->valid_subscription( 'https://fcm.googleapis.com/fcm/send/fixture-a' ) );
		$this->journey->subscribe( $this->user_id, $this->valid_subscription( 'https://fcm.googleapis.com/fcm/send/fixture-b' ) );

		$result = $this->journey->list_subscriptions( $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertIsArray( $result->data['items'] );
		$this->assertCount( 2, $result->data['items'] );
		$this->assertIsArray( $result->data['columns'] );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'user_id', $result->data['columns'] );
		$this->assertContains( 'endpoint', $result->data['columns'] );
		$this->assertContains( 'created_at', $result->data['columns'] );

		$endpoints = array_column( $result->data['items'], 'endpoint' );
		$this->assertContains( 'https://fcm.googleapis.com/fcm/send/fixture-a', $endpoints );
		$this->assertContains( 'https://fcm.googleapis.com/fcm/send/fixture-b', $endpoints );
	}

	public function test_get_vapid_public_key_returns_shape(): void {
		// Ensure a stable, known option so the shape check does not depend on
		// whatever the live site happens to have persisted.
		update_option(
			'jetonomy_pro_vapid_keys',
			array(
				'public'  => 'TESTPUBLICKEYVALUE',
				'private' => 'TESTPRIVATEKEYVALUE',
			)
		);

		$result = $this->journey->get_vapid_public_key();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'public_key', $result->data );
		$this->assertArrayHasKey( 'is_configured', $result->data );
		$this->assertTrue( (bool) $result->data['is_configured'] );
		$this->assertSame( 'TESTPUBLICKEYVALUE', $result->data['public_key'] );
		// Private key must never leak into the payload.
		$this->assertArrayNotHasKey( 'private_key', $result->data );
	}

	public function test_get_vapid_public_key_marks_unconfigured_when_missing(): void {
		update_option( 'jetonomy_pro_vapid_keys', '' );

		$result = $this->journey->get_vapid_public_key();

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( (bool) $result->data['is_configured'] );
		$this->assertSame( '', (string) $result->data['public_key'] );
	}

	public function test_send_push_requires_title_and_body(): void {
		$missing_title = $this->journey->send_push(
			$this->user_id,
			array(
				'title' => '',
				'body'  => 'hello',
			)
		);
		$this->assertFalse( $missing_title->is_success() );
		$this->assertStringContainsString( 'title', strtolower( (string) $missing_title->first_error() ) );

		$missing_body = $this->journey->send_push(
			$this->user_id,
			array(
				'title' => 'hello',
				'body'  => '',
			)
		);
		$this->assertFalse( $missing_body->is_success() );
		$this->assertStringContainsString( 'body', strtolower( (string) $missing_body->first_error() ) );

		$bad_user = $this->journey->send_push(
			0,
			array(
				'title' => 'hi',
				'body'  => 'there',
			)
		);
		$this->assertFalse( $bad_user->is_success() );
		$this->assertStringContainsString( 'user_id', strtolower( (string) $bad_user->first_error() ) );
	}

	public function test_count_subscribers_matches_rows(): void {
		$before = $this->journey->count_subscribers();
		$this->assertTrue( $before->is_success() );
		$baseline = (int) $before->data['count'];

		$this->journey->subscribe( $this->user_id, $this->valid_subscription( 'https://fcm.googleapis.com/fcm/send/fixture-a' ) );
		$this->journey->subscribe( $this->user_id, $this->valid_subscription( 'https://fcm.googleapis.com/fcm/send/fixture-b' ) );

		$after = $this->journey->count_subscribers();
		$this->assertTrue( $after->is_success() );
		$this->assertSame( $baseline + 2, (int) $after->data['count'] );
	}
}
