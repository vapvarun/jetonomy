<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy_Pro\CLI\Journeys\Reply_By_Email_Journey;

// Proactively load the Pro journey file. Pro's maybe_load_cli() only runs
// under WP-CLI (not PHPUnit), so autoloading wouldn't pick it up otherwise.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
if ( defined( 'WP_PLUGIN_DIR' ) && ! class_exists( Reply_By_Email_Journey::class ) ) {
	$jt_pro_journey_path = WP_PLUGIN_DIR . '/jetonomy-pro/includes/cli/journeys/class-reply-by-email-journey.php';
	if ( file_exists( $jt_pro_journey_path ) ) {
		require_once $jt_pro_journey_path;
	}
}
if ( ! class_exists( Reply_By_Email_Journey::class ) ) {
	$jt_pro_journey_fallback = dirname( __DIR__, 5 ) . '/jetonomy-pro/includes/cli/journeys/class-reply-by-email-journey.php';
	if ( file_exists( $jt_pro_journey_fallback ) ) {
		require_once $jt_pro_journey_fallback;
	}
}
// phpcs:enable WordPress.Files.FileName.InvalidClassFileName

/**
 * Integration tests for Reply_By_Email_Journey.
 *
 * The Pro plugin must be active during the test run so the
 * Reply-by-Email extension class (and the free-side Reply model) are
 * reachable. Each test seeds a fresh category/space/post/user trio,
 * produces tokens through the journey, and asserts against the live
 * `jt_replies` table when exercising the full pipeline.
 */
class ReplyByEmailJourneyTest extends WP_UnitTestCase {

	private Reply_By_Email_Journey $journey;

	private int $user_id;
	private int $space_id;
	private int $post_id;

	/**
	 * Reply ids created during a single test — tear_down wipes only these.
	 *
	 * @var int[]
	 */
	private array $created_reply_ids = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Reply_By_Email_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Reply_By_Email_Journey.' );
		}

		$this->journey           = new Reply_By_Email_Journey();
		$this->created_reply_ids = array();

		$this->user_id = (int) self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'rbe-journey-' . uniqid() . '@example.test',
			)
		);

		$cat_id = Category::create(
			array(
				'name' => 'RBE Journey Cat',
				'slug' => 'rbe-journey-cat-' . uniqid(),
			)
		);

		$this->space_id = (int) Space::create(
			array(
				'title'       => 'RBE Journey Space',
				'slug'        => 'rbe-journey-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
			)
		);

		$post_id_or_error = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->user_id,
				'title'     => 'RBE Journey Post',
				'slug'      => 'rbe-journey-post-' . uniqid(),
				'content'   => '<p>Journey fixture</p>',
				'status'    => 'publish',
			)
		);
		$this->post_id    = is_int( $post_id_or_error ) ? $post_id_or_error : 0;
	}

	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_replies';
		foreach ( $this->created_reply_ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
		}
		$this->created_reply_ids = array();

		// Wipe the failure ring buffer so tests don't bleed into one another.
		delete_option( 'jetonomy_pro_reply_by_email_failures' );

		parent::tear_down();
	}

	/*
	 * --------------------------------------------------------------------
	 * Token generation + validation
	 * -------------------------------------------------------------------- */

	public function test_generate_reply_token_returns_string(): void {
		$result = $this->journey->generate_reply_token( $this->post_id, $this->user_id );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'token', $result->data );
		$this->assertIsString( $result->data['token'] );
		$this->assertNotSame( '', $result->data['token'] );
		$this->assertArrayHasKey( 'reply_to_address', $result->data );
		$this->assertStringContainsString( 'reply+', (string) $result->data['reply_to_address'] );
		$this->assertSame( $this->post_id, (int) $result->data['post_id'] );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );
	}

	public function test_validate_reply_token_roundtrip(): void {
		$gen = $this->journey->generate_reply_token( $this->post_id, $this->user_id );
		$this->assertTrue( $gen->is_success() );
		$token = (string) $gen->data['token'];

		$val = $this->journey->validate_reply_token( $token );

		$this->assertTrue( $val->is_success() );
		$this->assertTrue( (bool) $val->data['valid'] );
		$this->assertSame( $this->post_id, (int) $val->data['post_id'] );
		$this->assertSame( $this->user_id, (int) $val->data['user_id'] );
		$this->assertGreaterThan( time(), (int) $val->data['expires_at'] );
	}

	public function test_validate_reply_token_rejects_malformed_token(): void {
		$val = $this->journey->validate_reply_token( 'not-a-real-token' );

		$this->assertTrue( $val->is_success() );
		$this->assertFalse( (bool) $val->data['valid'] );
		$this->assertArrayHasKey( 'error', $val->data );
		$this->assertNotSame( '', (string) $val->data['error'] );
	}

	/*
	 * --------------------------------------------------------------------
	 * Parse
	 * -------------------------------------------------------------------- */

	public function test_parse_inbound_email_extracts_reply_token(): void {
		$gen   = $this->journey->generate_reply_token( $this->post_id, $this->user_id );
		$token = (string) $gen->data['token'];
		$to    = (string) $gen->data['reply_to_address'];

		$result = $this->journey->parse_inbound_email(
			array(
				'From'    => 'alice@example.test',
				'To'      => $to,
				'Subject' => 'Re: RBE Journey Post',
				'Body'    => "This is my reply.\n> quoted line",
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( $token, (string) $result->data['reply_token'] );
		$this->assertSame( $this->post_id, (int) $result->data['post_id'] );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );
		$this->assertStringContainsString( 'This is my reply.', (string) $result->data['body_plain'] );
		$this->assertStringNotContainsString( '> quoted line', (string) $result->data['body_plain'] );
	}

	public function test_parse_inbound_email_rejects_missing_from(): void {
		$gen = $this->journey->generate_reply_token( $this->post_id, $this->user_id );

		$result = $this->journey->parse_inbound_email(
			array(
				'To'   => (string) $gen->data['reply_to_address'],
				'Body' => 'no sender',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'from', strtolower( (string) $result->first_error() ) );
	}

	/*
	 * --------------------------------------------------------------------
	 * Process
	 * -------------------------------------------------------------------- */

	public function test_process_inbound_email_creates_reply(): void {
		global $wpdb;

		$gen = $this->journey->generate_reply_token( $this->post_id, $this->user_id );
		$to  = (string) $gen->data['reply_to_address'];

		$result = $this->journey->process_inbound_email(
			array(
				'From'    => 'alice@example.test',
				'To'      => $to,
				'Subject' => 'Re: RBE Journey Post',
				'Body'    => 'Persisted reply from the RBE journey test.',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( (bool) $result->data['success'] );
		$this->assertSame( $this->post_id, (int) $result->data['post_id'] );
		$this->assertSame( $this->user_id, (int) $result->data['author_id'] );

		$reply_id = (int) $result->data['reply_id'];
		$this->assertGreaterThan( 0, $reply_id );
		$this->created_reply_ids[] = $reply_id;

		$table = $wpdb->prefix . 'jt_replies';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_id, author_id, content FROM {$table} WHERE id = %d", $reply_id ) );

		$this->assertNotNull( $row );
		$this->assertSame( $this->post_id, (int) $row->post_id );
		$this->assertSame( $this->user_id, (int) $row->author_id );
		$this->assertStringContainsString( 'Persisted reply', (string) $row->content );
	}

	public function test_process_inbound_email_rejects_invalid_token(): void {
		$result = $this->journey->process_inbound_email(
			array(
				'From'    => 'alice@example.test',
				'To'      => 'reply+totally-bogus-token@example.com',
				'Subject' => 'Re: test',
				'Body'    => 'This should never land in jt_replies.',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'token', strtolower( (string) $result->first_error() ) );

		// Failure ring buffer must have captured the reject.
		$failures = $this->journey->list_recent_failures( 5 );
		$this->assertTrue( $failures->is_success() );
		$this->assertGreaterThanOrEqual( 1, (int) $failures->data['count'] );
	}

	/*
	 * --------------------------------------------------------------------
	 * Config
	 * -------------------------------------------------------------------- */

	public function test_get_config_returns_shape_without_secret(): void {
		// Ensure no secret is configured.
		delete_option( 'jetonomy_pro_reply_by_email' );

		$result = $this->journey->get_config();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'inbound_webhook_url', $result->data );
		$this->assertArrayHasKey( 'provider', $result->data );
		$this->assertArrayHasKey( 'from_domain', $result->data );
		$this->assertArrayHasKey( 'secret_set', $result->data );
		$this->assertArrayHasKey( 'supported_providers', $result->data );
		$this->assertArrayNotHasKey( 'shared_secret', $result->data );
		$this->assertArrayNotHasKey( 'webhook_secret', $result->data );
		$this->assertFalse( (bool) $result->data['secret_set'] );
		$this->assertStringContainsString( 'reply-by-email/inbound', (string) $result->data['inbound_webhook_url'] );
	}

	public function test_get_config_reports_secret_set_when_configured(): void {
		update_option(
			'jetonomy_pro_reply_by_email',
			array(
				'enabled'        => true,
				'method'         => 'webhook',
				'webhook_secret' => 'test-secret-please-ignore',
				'email_domain'   => 'forums.local',
			)
		);

		$result = $this->journey->get_config();

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( (bool) $result->data['secret_set'] );
		$this->assertSame( 'webhook', (string) $result->data['provider'] );
		$this->assertSame( 'forums.local', (string) $result->data['from_domain'] );

		// The secret value itself must never appear in the response.
		$encoded = (string) wp_json_encode( $result->data );
		$this->assertStringNotContainsString( 'test-secret-please-ignore', $encoded );
	}

	public function test_update_config_whitelists_fields(): void {
		$result = $this->journey->update_config(
			array(
				'provider'       => 'webhook',
				'shared_secret'  => 'cli-secret',
				'from_domain'    => 'forums.example.com',
				'auto_subscribe' => true,
				'malicious_key'  => 'should-be-dropped',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertContains( 'provider', (array) $result->data['updated'] );
		$this->assertContains( 'shared_secret', (array) $result->data['updated'] );
		$this->assertContains( 'from_domain', (array) $result->data['updated'] );
		$this->assertContains( 'auto_subscribe', (array) $result->data['updated'] );
		$this->assertNotContains( 'malicious_key', (array) $result->data['updated'] );

		$saved = get_option( 'jetonomy_pro_reply_by_email' );
		$this->assertIsArray( $saved );
		$this->assertSame( 'webhook', (string) $saved['method'] );
		$this->assertSame( 'cli-secret', (string) $saved['webhook_secret'] );
		$this->assertSame( 'forums.example.com', (string) $saved['email_domain'] );
		$this->assertTrue( (bool) $saved['auto_subscribe'] );
		$this->assertArrayNotHasKey( 'malicious_key', $saved );
	}

	public function test_update_config_rejects_unknown_provider(): void {
		$result = $this->journey->update_config( array( 'provider' => 'postmark' ) );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'provider', strtolower( (string) $result->first_error() ) );
	}

	/*
	 * --------------------------------------------------------------------
	 * Send test
	 * -------------------------------------------------------------------- */

	public function test_send_test_inbound_end_to_end(): void {
		global $wpdb;

		$result = $this->journey->send_test_inbound(
			$this->post_id,
			$this->user_id,
			'End-to-end smoke test body.'
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( (bool) $result->data['success'] );

		$reply_id = (int) $result->data['reply_id'];
		$this->assertGreaterThan( 0, $reply_id );
		$this->created_reply_ids[] = $reply_id;

		$table = $wpdb->prefix . 'jt_replies';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$content = (string) $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$table} WHERE id = %d", $reply_id ) );
		$this->assertStringContainsString( 'End-to-end smoke test body.', $content );
	}
}
