<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Email_Digest_Journey;

// Proactively load the Pro journey file. Pro's maybe_load_cli() only runs
// under WP-CLI (not PHPUnit), so autoloading wouldn't pick it up otherwise.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
if ( defined( 'WP_PLUGIN_DIR' ) && ! class_exists( Email_Digest_Journey::class ) ) {
	$jt_pro_journey_path = WP_PLUGIN_DIR . '/jetonomy-pro/includes/cli/journeys/class-email-digest-journey.php';
	if ( file_exists( $jt_pro_journey_path ) ) {
		require_once $jt_pro_journey_path;
	}
}
if ( ! class_exists( Email_Digest_Journey::class ) ) {
	$jt_pro_journey_fallback = dirname( __DIR__, 5 ) . '/jetonomy-pro/includes/cli/journeys/class-email-digest-journey.php';
	if ( file_exists( $jt_pro_journey_fallback ) ) {
		require_once $jt_pro_journey_fallback;
	}
}
// phpcs:enable WordPress.Files.FileName.InvalidClassFileName

/**
 * Integration tests for Email_Digest_Journey against the live Email Digest
 * extension.
 *
 * The Pro plugin must be active during the test run so the extension is
 * registered. Each test installs a `pre_wp_mail` filter to intercept
 * outgoing messages so `send_test_digest()` can be asserted against
 * without actually delivering mail through the SMTP stack.
 */
class EmailDigestJourneyTest extends WP_UnitTestCase {

	private Email_Digest_Journey $journey;

	private int $user_id;

	/**
	 * Outgoing messages captured by the wp_mail filter — populated per-test
	 * so send_test_digest() can be asserted without hitting real delivery.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $captured_mail = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Email_Digest_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Email_Digest_Journey.' );
		}

		$this->journey = new Email_Digest_Journey();

		$this->user_id = (int) self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'digest-test-' . wp_generate_password( 8, false ) . '@example.test',
			)
		);

		// Install the wp_mail short-circuit: return the args array instead of
		// the expected boolean so PHPMailer never runs. wp_mail() treats a
		// non-null filter return as "handled" and returns true to the caller,
		// which is exactly what send_test_digest() expects for "sent: true".
		$this->captured_mail = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->captured_mail[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		delete_user_meta( $this->user_id, 'jetonomy_pro_digest_frequency' );
		delete_user_meta( $this->user_id, 'jetonomy_pro_digest_enabled' );
		delete_user_meta( $this->user_id, 'jetonomy_pro_digest_subscribed_types' );
		parent::tear_down();
	}

	public function test_get_preferences_returns_defaults_for_new_user(): void {
		$result = $this->journey->get_preferences( $this->user_id );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );
		$this->assertContains( $result->data['frequency'], array( 'none', 'daily', 'weekly' ) );
		$this->assertIsBool( $result->data['enabled'] );
		$this->assertIsArray( $result->data['subscribed_types'] );
	}

	public function test_set_preferences_persists_valid_frequency(): void {
		$result = $this->journey->set_preferences(
			$this->user_id,
			array( 'frequency' => 'daily' )
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 'daily', $result->data['applied']['frequency'] );
		$this->assertSame( 'daily', (string) get_user_meta( $this->user_id, 'jetonomy_pro_digest_frequency', true ) );

		// Read-after-write via the journey to confirm round-tripping.
		$prefs = $this->journey->get_preferences( $this->user_id );
		$this->assertSame( 'daily', $prefs->data['frequency'] );
	}

	public function test_set_preferences_rejects_invalid_frequency(): void {
		$result = $this->journey->set_preferences(
			$this->user_id,
			array( 'frequency' => 'monthly' )
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'frequency', strtolower( (string) $result->first_error() ) );
	}

	public function test_set_preferences_whitelists_keys(): void {
		$result = $this->journey->set_preferences(
			$this->user_id,
			array(
				'frequency' => 'weekly',
				'nefarious' => 'value',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'nefarious', (string) $result->first_error() );
	}

	public function test_send_test_intercepts_wp_mail(): void {
		$result = $this->journey->send_test_digest( $this->user_id );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( $result->data['sent'] );
		$this->assertNotEmpty( $result->data['subject'] );
		$this->assertSame( get_userdata( $this->user_id )->user_email, $result->data['to'] );

		// Confirm the filter intercepted at least one delivery attempt.
		$this->assertNotEmpty( $this->captured_mail );
		$this->assertSame(
			get_userdata( $this->user_id )->user_email,
			(string) ( $this->captured_mail[0]['to'] ?? '' )
		);
	}

	public function test_send_test_requires_positive_user_id(): void {
		$result = $this->journey->send_test_digest( 0 );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'user_id', strtolower( (string) $result->first_error() ) );
	}

	public function test_preview_returns_html_and_subject(): void {
		$result = $this->journey->preview_digest( $this->user_id, 'weekly' );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'html', $result->data );
		$this->assertArrayHasKey( 'subject', $result->data );
		$this->assertArrayHasKey( 'item_count', $result->data );
		$this->assertIsString( $result->data['html'] );
		$this->assertNotEmpty( $result->data['subject'] );
		$this->assertIsInt( $result->data['item_count'] );

		// Previewing must never deliver mail.
		$this->assertEmpty( $this->captured_mail );
	}

	public function test_trigger_cron_processes_subscribers(): void {
		// Opt our test user in so the subscriber count is non-zero.
		$this->journey->set_preferences( $this->user_id, array( 'frequency' => 'daily' ) );

		$result = $this->journey->trigger_cron( 'daily' );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 'daily', $result->data['frequency'] );
		$this->assertGreaterThanOrEqual( 1, (int) $result->data['users_processed'] );
		$this->assertSame( 'jetonomy_pro_send_daily_digest', $result->data['hook'] );
	}

	public function test_get_stats_returns_shape(): void {
		$result = $this->journey->get_stats();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'total_subscribers', $result->data );
		$this->assertArrayHasKey( 'daily_subscribers', $result->data );
		$this->assertArrayHasKey( 'weekly_subscribers', $result->data );
		$this->assertArrayHasKey( 'last_run_daily', $result->data );
		$this->assertArrayHasKey( 'next_run_daily', $result->data );
		$this->assertIsInt( $result->data['total_subscribers'] );
	}

	public function test_list_subscribers_filters_by_frequency(): void {
		// Opt user in to weekly.
		$this->journey->set_preferences( $this->user_id, array( 'frequency' => 'weekly' ) );

		$result = $this->journey->list_subscribers( 'weekly' );
		$this->assertTrue( $result->is_success() );
		$this->assertIsArray( $result->data['items'] );
		$ids = array_column( $result->data['items'], 'user_id' );
		$this->assertContains( $this->user_id, array_map( 'intval', $ids ) );

		// Filtering to daily should NOT include a weekly-only subscriber.
		$daily_only = $this->journey->list_subscribers( 'daily' );
		$this->assertTrue( $daily_only->is_success() );
		$daily_ids = array_column( $daily_only->data['items'], 'user_id' );
		$this->assertNotContains( $this->user_id, array_map( 'intval', $daily_ids ) );
	}
}
