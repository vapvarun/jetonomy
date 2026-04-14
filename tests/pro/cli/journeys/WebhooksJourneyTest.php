<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Webhooks_Journey;

/**
 * Integration tests for Webhooks_Journey against the live
 * `wp_jt_pro_webhooks` table.
 *
 * The Pro plugin must be active during the test run so the webhooks table
 * exists (it is created in Webhooks\Extension::activate() via dbDelta).
 * Each test inserts its own rows and tear_down() wipes everything the
 * journey created.
 *
 * HTTP interception strategy for test_webhook():
 *     test_webhook() hits the live URL via wp_remote_post(). We short-
 *     circuit that globally through the `pre_http_request` filter installed
 *     in set_up(), which forces every outbound HTTP call from this test
 *     class to resolve to a canned 202 response without touching the
 *     network. The filter is removed in tear_down(). This is the same
 *     interception pattern used by WebPushJourneyTest.
 */
class WebhooksJourneyTest extends WP_UnitTestCase {

	private Webhooks_Journey $journey;

	/**
	 * Ids created by this test — teardown wipes only these rows.
	 *
	 * @var int[]
	 */
	private array $created_ids = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Webhooks_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Webhooks_Journey.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_webhooks';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( ! $exists ) {
			$this->markTestSkipped( 'Webhooks extension table is missing — activate the webhooks extension before running this test.' );
		}

		$this->journey     = new Webhooks_Journey();
		$this->created_ids = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_webhooks';
		foreach ( $this->created_ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
		}
		$this->created_ids = array();

		parent::tear_down();
	}

	/**
	 * Canned HTTP response so test_webhook() never hits the network.
	 *
	 * @param false|array|\WP_Error $preempt Existing preempt value.
	 * @param array                 $args    Request args.
	 * @param string                $url     Target URL.
	 * @return array<string,mixed>
	 */
	public function intercept_http( $preempt, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array(
				'code'    => 202,
				'message' => 'Accepted',
			),
			'cookies'  => array(),
			'filename' => '',
		);
	}

	/**
	 * Create a webhook via the journey and track the id for teardown.
	 *
	 * @param array<string,mixed> $overrides Overrides for the default payload.
	 */
	private function create_fixture( array $overrides = array() ): Journey_Result {
		$payload = array_merge(
			array(
				'url'    => 'https://example.com/hook',
				'events' => array( 'post.created' ),
			),
			$overrides
		);
		$result  = $this->journey->create_webhook( $payload );
		if ( $result->is_success() && isset( $result->data['webhook_id'] ) ) {
			$this->created_ids[] = (int) $result->data['webhook_id'];
		}
		return $result;
	}

	public function test_create_webhook_persists_row(): void {
		global $wpdb;

		$result = $this->create_fixture(
			array(
				'url'    => 'https://example.com/hook-a',
				'events' => array( 'post.created', 'reply.created' ),
			)
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertGreaterThan( 0, (int) $result->data['webhook_id'] );
		$this->assertSame( 'https://example.com/hook-a', $result->data['url'] );
		$this->assertSame( array( 'post.created', 'reply.created' ), $result->data['events'] );
		$this->assertTrue( (bool) $result->data['enabled'] );

		$id    = (int) $result->data['webhook_id'];
		$table = $wpdb->prefix . 'jt_pro_webhooks';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT url, events, is_active FROM {$table} WHERE id = %d", $id ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'https://example.com/hook-a', $row->url );
		$this->assertSame( 1, (int) $row->is_active );
		$this->assertSame( array( 'post.created', 'reply.created' ), json_decode( $row->events, true ) );
	}

	public function test_create_webhook_generates_secret_when_absent(): void {
		global $wpdb;

		$result = $this->create_fixture();
		$this->assertTrue( $result->is_success() );

		$id    = (int) $result->data['webhook_id'];
		$table = $wpdb->prefix . 'jt_pro_webhooks';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$secret = (string) $wpdb->get_var( $wpdb->prepare( "SELECT secret FROM {$table} WHERE id = %d", $id ) );
		$this->assertNotSame( '', $secret );
		$this->assertGreaterThanOrEqual( 20, strlen( $secret ) );

		// And the wire payload must report secret_set but never the value.
		$this->assertTrue( (bool) $result->data['secret_set'] );
		$this->assertArrayNotHasKey( 'secret', $result->data );
	}

	public function test_create_webhook_rejects_non_http_url(): void {
		$bad = $this->journey->create_webhook(
			array(
				'url'    => 'javascript:alert(1)',
				'events' => array( 'post.created' ),
			)
		);
		$this->assertFalse( $bad->is_success() );
		$this->assertStringContainsString( 'url', strtolower( (string) $bad->first_error() ) );

		$empty = $this->journey->create_webhook(
			array(
				'url'    => '',
				'events' => array( 'post.created' ),
			)
		);
		$this->assertFalse( $empty->is_success() );

		$ftp = $this->journey->create_webhook(
			array(
				'url'    => 'ftp://example.com/hook',
				'events' => array( 'post.created' ),
			)
		);
		$this->assertFalse( $ftp->is_success() );
	}

	public function test_create_webhook_rejects_empty_events(): void {
		$missing = $this->journey->create_webhook(
			array(
				'url' => 'https://example.com/hook',
			)
		);
		$this->assertFalse( $missing->is_success() );
		$this->assertStringContainsString( 'events', strtolower( (string) $missing->first_error() ) );

		$empty = $this->journey->create_webhook(
			array(
				'url'    => 'https://example.com/hook',
				'events' => array(),
			)
		);
		$this->assertFalse( $empty->is_success() );

		$unknown = $this->journey->create_webhook(
			array(
				'url'    => 'https://example.com/hook',
				'events' => array( 'not.a.real.event' ),
			)
		);
		$this->assertFalse( $unknown->is_success() );
		$this->assertStringContainsString( 'unknown', strtolower( (string) $unknown->first_error() ) );
	}

	public function test_update_webhook_whitelists_fields(): void {
		global $wpdb;

		$created = $this->create_fixture();
		$id      = (int) $created->data['webhook_id'];

		$result = $this->journey->update_webhook(
			$id,
			array(
				'url'              => 'https://example.com/hook-updated',
				'events'           => array( 'reply.created' ),
				// These are not whitelisted and must be dropped silently.
				'created_by'       => 99999,
				'fail_count'       => 42,
				'last_status_code' => 500,
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertContains( 'url', $result->data['updated'] );
		$this->assertContains( 'events', $result->data['updated'] );
		$this->assertNotContains( 'created_by', $result->data['updated'] );

		$table = $wpdb->prefix . 'jt_pro_webhooks';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT url, events, fail_count, created_by FROM {$table} WHERE id = %d", $id ) );
		$this->assertSame( 'https://example.com/hook-updated', $row->url );
		$this->assertSame( array( 'reply.created' ), json_decode( $row->events, true ) );
		// Sanity: the non-whitelisted fields did not get through.
		$this->assertNotSame( 42, (int) $row->fail_count );
		$this->assertNotSame( 99999, (int) $row->created_by );

		// Rejects empty change set.
		$noop = $this->journey->update_webhook( $id, array( 'bogus' => 'x' ) );
		$this->assertFalse( $noop->is_success() );
	}

	public function test_delete_webhook_removes_row(): void {
		global $wpdb;

		$created = $this->create_fixture();
		$id      = (int) $created->data['webhook_id'];

		$result = $this->journey->delete_webhook( $id );
		$this->assertTrue( $result->is_success() );
		$this->assertTrue( (bool) $result->data['removed'] );

		$table = $wpdb->prefix . 'jt_pro_webhooks';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) );
		$this->assertSame( 0, $count );

		// Subsequent delete is a noop, not an error.
		$noop = $this->journey->delete_webhook( $id );
		$this->assertTrue( $noop->is_success() );
		$this->assertFalse( (bool) $noop->data['removed'] );

		// Drop the id from the fixture list — it's already gone.
		$this->created_ids = array_values( array_diff( $this->created_ids, array( $id ) ) );
	}

	public function test_list_webhooks_never_exposes_secret(): void {
		$this->create_fixture( array( 'url' => 'https://example.com/hook-1' ) );
		$this->create_fixture(
			array(
				'url'     => 'https://example.com/hook-2',
				'enabled' => false,
			)
		);

		$all = $this->journey->list_webhooks();
		$this->assertTrue( $all->is_success() );
		$this->assertIsArray( $all->data['items'] );
		$this->assertGreaterThanOrEqual( 2, count( $all->data['items'] ) );
		$this->assertContains( 'secret_set', $all->data['columns'] );
		$this->assertNotContains( 'secret', $all->data['columns'] );

		foreach ( $all->data['items'] as $item ) {
			$this->assertArrayHasKey( 'secret_set', $item );
			$this->assertArrayNotHasKey( 'secret', $item );
			$this->assertTrue( (bool) $item['secret_set'] );
		}

		$enabled_only = $this->journey->list_webhooks( true );
		$this->assertTrue( $enabled_only->is_success() );
		foreach ( $enabled_only->data['items'] as $item ) {
			$this->assertTrue( (bool) $item['enabled'] );
		}

		$disabled_only = $this->journey->list_webhooks( false );
		$this->assertTrue( $disabled_only->is_success() );
		foreach ( $disabled_only->data['items'] as $item ) {
			$this->assertFalse( (bool) $item['enabled'] );
		}
	}

	public function test_get_webhook_hides_secret(): void {
		$created = $this->create_fixture();
		$id      = (int) $created->data['webhook_id'];

		$result = $this->journey->get_webhook( $id );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( $id, (int) $result->data['id'] );
		$this->assertArrayHasKey( 'secret_set', $result->data );
		$this->assertArrayNotHasKey( 'secret', $result->data );
		$this->assertTrue( (bool) $result->data['secret_set'] );

		$missing = $this->journey->get_webhook( 999999999 );
		$this->assertFalse( $missing->is_success() );
	}

	public function test_enable_and_disable_webhook_toggle(): void {
		$created = $this->create_fixture();
		$id      = (int) $created->data['webhook_id'];

		$disabled = $this->journey->disable_webhook( $id );
		$this->assertTrue( $disabled->is_success() );
		$this->assertFalse( (bool) $disabled->data['enabled'] );

		$after_disable = $this->journey->get_webhook( $id );
		$this->assertFalse( (bool) $after_disable->data['enabled'] );

		$enabled = $this->journey->enable_webhook( $id );
		$this->assertTrue( $enabled->is_success() );
		$this->assertTrue( (bool) $enabled->data['enabled'] );

		$after_enable = $this->journey->get_webhook( $id );
		$this->assertTrue( (bool) $after_enable->data['enabled'] );
		// enable_webhook resets fail_count so the extension's auto-disable
		// threshold doesn't fire on the next failed event.
		$this->assertSame( 0, (int) $after_enable->data['fail_count'] );
	}

	public function test_test_webhook_hits_interceptor(): void {
		$created = $this->create_fixture();
		$id      = (int) $created->data['webhook_id'];

		$captured = array(
			'url'       => null,
			'body'      => null,
			'signature' => null,
		);
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured ) {
				$captured['url']       = $url;
				$captured['body']      = $args['body'] ?? null;
				$captured['signature'] = $args['headers']['X-Jetonomy-Signature'] ?? null;
				return array(
					'headers'  => array(),
					'body'     => '{"ok":true}',
					'response' => array(
						'code'    => 202,
						'message' => 'Accepted',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			5,
			3
		);

		$result = $this->journey->test_webhook( $id, array( 'hello' => 'world' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'https://example.com/hook', $captured['url'] );
		$this->assertIsString( $captured['body'] );
		$this->assertStringContainsString( '"hello":"world"', (string) $captured['body'] );
		$this->assertIsString( $captured['signature'] );
		$this->assertStringStartsWith( 'sha256=', (string) $captured['signature'] );
	}

	public function test_test_webhook_reports_status_code(): void {
		$created = $this->create_fixture();
		$id      = (int) $created->data['webhook_id'];

		$result = $this->journey->test_webhook( $id );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 202, (int) $result->data['status_code'] );
		$this->assertTrue( (bool) $result->data['success'] );
		$this->assertSame( $id, (int) $result->data['webhook_id'] );
		$this->assertArrayHasKey( 'duration_ms', $result->data );
		$this->assertArrayHasKey( 'response_body_preview', $result->data );

		$missing = $this->journey->test_webhook( 999999999 );
		$this->assertFalse( $missing->is_success() );
	}

	public function test_list_supported_events_returns_nonempty_list(): void {
		$result = $this->journey->list_supported_events();
		$this->assertTrue( $result->is_success() );
		$this->assertIsArray( $result->data['items'] );
		$this->assertGreaterThan( 0, count( $result->data['items'] ) );
		$this->assertSame( array( 'event' ), $result->data['columns'] );

		$events = array_column( $result->data['items'], 'event' );
		$this->assertContains( 'post.created', $events );
		$this->assertContains( 'reply.created', $events );
		$this->assertContains( 'user.registered', $events );
		$this->assertContains( 'flag.created', $events );
	}
}
