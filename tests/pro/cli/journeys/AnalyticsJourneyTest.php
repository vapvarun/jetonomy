<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
use Jetonomy_Pro\CLI\Journeys\Analytics_Journey;

// Proactively load the Pro journey file. Pro's maybe_load_cli() only runs
// under WP-CLI (not PHPUnit), so autoloading wouldn't pick it up otherwise.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
if ( defined( 'WP_PLUGIN_DIR' ) && ! class_exists( Analytics_Journey::class ) ) {
	$jt_pro_journey_path = WP_PLUGIN_DIR . '/jetonomy-pro/includes/cli/journeys/class-analytics-journey.php';
	if ( file_exists( $jt_pro_journey_path ) ) {
		require_once $jt_pro_journey_path;
	}
}
if ( ! class_exists( Analytics_Journey::class ) ) {
	$jt_pro_journey_fallback = dirname( __DIR__, 5 ) . '/jetonomy-pro/includes/cli/journeys/class-analytics-journey.php';
	if ( file_exists( $jt_pro_journey_fallback ) ) {
		require_once $jt_pro_journey_fallback;
	}
}
// phpcs:enable WordPress.Files.FileName.InvalidClassFileName

/**
 * Integration tests for Analytics_Journey against the live Analytics
 * extension and the core `jt_*` analytics tables.
 *
 * The Pro plugin must be active during the test run so the extension is
 * registered and the REST handlers are reachable. Each test seeds a fresh
 * category/space/post/reply trio so the overview/top-spaces/top-contributors
 * queries return non-empty results.
 */
class AnalyticsJourneyTest extends WP_UnitTestCase {

	private Analytics_Journey $journey;

	private int $user_id;
	private int $space_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Analytics_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Analytics_Journey.' );
		}

		$this->journey = new Analytics_Journey();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Ensure jt_user_profiles row exists — top_contributors INNER JOINs on it
		// and would otherwise exclude factory-created users that never touched
		// the profile model.
		UserProfile::find_or_create( $this->user_id );

		$cat_id = Category::create(
			array(
				'name' => 'Analytics Journey Cat',
				'slug' => 'analytics-journey-cat-' . uniqid(),
			)
		);

		$this->space_id = (int) Space::create(
			array(
				'title'       => 'Analytics Journey Space',
				'slug'        => 'analytics-journey-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
			)
		);

		$post_id_or_error = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->user_id,
				'title'     => 'Analytics Journey Post',
				'slug'      => 'analytics-journey-post-' . uniqid(),
				'content'   => '<p>Journey fixture</p>',
				'status'    => 'publish',
			)
		);
		$this->post_id    = is_int( $post_id_or_error ) ? $post_id_or_error : 0;

		// Seed a reply so engagement / overview queries have something to
		// aggregate — otherwise avg_reply_time would be NULL and totals 0.
		Reply::create(
			array(
				'post_id'   => $this->post_id,
				'author_id' => $this->user_id,
				'content'   => '<p>Journey fixture reply</p>',
				'status'    => 'publish',
			)
		);
	}

	public function test_overview_returns_series_shape(): void {
		$result = $this->journey->overview( '7d', null, null );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );

		$this->assertArrayHasKey( 'range', $result->data );
		$this->assertArrayHasKey( 'series', $result->data );
		$this->assertArrayHasKey( 'totals', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );

		$this->assertSame( '7d', $result->data['range'] );
		$this->assertIsArray( $result->data['series'] );
		$this->assertIsArray( $result->data['totals'] );
		$this->assertArrayHasKey( 'posts', $result->data['totals'] );
		$this->assertArrayHasKey( 'replies', $result->data['totals'] );
		$this->assertArrayHasKey( 'new_users', $result->data['totals'] );

		// We seeded at least one post + one reply in set_up(), so totals
		// must reflect them.
		$this->assertGreaterThanOrEqual( 1, (int) $result->data['totals']['posts'] );
		$this->assertGreaterThanOrEqual( 1, (int) $result->data['totals']['replies'] );
	}

	public function test_overview_rejects_invalid_range(): void {
		$result = $this->journey->overview( '42d', null, null );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'range', strtolower( (string) $result->first_error() ) );
	}

	public function test_overview_rejects_custom_range_without_start_end(): void {
		$missing_both = $this->journey->overview( 'custom', null, null );
		$this->assertFalse( $missing_both->is_success() );
		$this->assertStringContainsString( 'custom', strtolower( (string) $missing_both->first_error() ) );

		$missing_end = $this->journey->overview( 'custom', '2026-03-01', null );
		$this->assertFalse( $missing_end->is_success() );
		$this->assertStringContainsString( 'custom', strtolower( (string) $missing_end->first_error() ) );
	}

	public function test_top_spaces_returns_items_and_columns(): void {
		// Request a large limit so the fixture is found regardless of how many
		// other spaces exist in the test DB (wp-env's persisted state can hold
		// dozens of fixtures from prior runs that out-rank our 1-post seed).
		$result = $this->journey->top_spaces( '30d', 100 );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertIsArray( $result->data['items'] );
		$this->assertIsArray( $result->data['columns'] );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'period_posts', $result->data['columns'] );

		// The fixture we just seeded should be present.
		$space_ids = array_map( 'intval', array_column( $result->data['items'], 'id' ) );
		$this->assertContains( $this->space_id, $space_ids );
	}

	public function test_top_spaces_respects_limit(): void {
		// Seed a couple more spaces so the limit actually trims something.
		$cat_id = Category::create(
			array(
				'name' => 'Analytics Extra Cat',
				'slug' => 'analytics-extra-cat-' . uniqid(),
			)
		);
		for ( $i = 0; $i < 3; $i++ ) {
			$sid = (int) Space::create(
				array(
					'title'       => 'Extra Space ' . $i,
					'slug'        => 'analytics-extra-' . $i . '-' . uniqid(),
					'category_id' => $cat_id,
					'visibility'  => 'public',
				)
			);
			Post::create(
				array(
					'space_id'  => $sid,
					'author_id' => $this->user_id,
					'title'     => 'Extra Post ' . $i,
					'slug'      => 'extra-post-' . $i . '-' . uniqid(),
					'content'   => '<p>Extra fixture</p>',
					'status'    => 'publish',
				)
			);
		}

		$result = $this->journey->top_spaces( '30d', 2 );
		$this->assertTrue( $result->is_success() );
		$this->assertLessThanOrEqual( 2, count( $result->data['items'] ) );

		$rejected = $this->journey->top_spaces( '30d', 0 );
		$this->assertFalse( $rejected->is_success() );
		$this->assertStringContainsString( 'limit', strtolower( (string) $rejected->first_error() ) );
	}

	public function test_top_contributors_returns_items_and_columns(): void {
		// Bump limit for the same reason as test_top_spaces_returns_items_and_columns:
		// the fresh fixture user has 1 post + 1 reply and gets out-ranked by
		// long-lived fixtures persisted across wp-env test runs.
		$result = $this->journey->top_contributors( '30d', 100 );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertIsArray( $result->data['items'] );
		$this->assertIsArray( $result->data['columns'] );
		$this->assertContains( 'user_id', $result->data['columns'] );
		$this->assertContains( 'period_posts', $result->data['columns'] );
		$this->assertContains( 'period_replies', $result->data['columns'] );

		// The fixture author should appear (author of 1 post + 1 reply).
		$user_ids = array_map( 'intval', array_column( $result->data['items'], 'user_id' ) );
		$this->assertContains( $this->user_id, $user_ids );
	}

	public function test_engagement_returns_metrics(): void {
		$result = $this->journey->engagement( '30d' );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'total_posts', $result->data );
		$this->assertArrayHasKey( 'total_replies', $result->data );
		$this->assertArrayHasKey( 'overall_rate', $result->data );
		$this->assertArrayHasKey( 'unanswered_count', $result->data );
		$this->assertArrayHasKey( 'unanswered_pct', $result->data );
		$this->assertArrayHasKey( 'series', $result->data );

		$this->assertGreaterThanOrEqual( 1, (int) $result->data['total_posts'] );
		$this->assertGreaterThanOrEqual( 1, (int) $result->data['total_replies'] );
	}

	public function test_moderation_returns_metrics(): void {
		$result = $this->journey->moderation( '30d' );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'flags_created', $result->data );
		$this->assertArrayHasKey( 'flags_pending', $result->data );
		$this->assertArrayHasKey( 'bans_issued', $result->data );
		$this->assertArrayHasKey( 'silences_issued', $result->data );
		$this->assertArrayHasKey( 'spam_total', $result->data );

		// Counts may be zero on a fresh test DB, but the fields exist.
		$this->assertIsInt( $result->data['flags_created'] );
		$this->assertIsInt( $result->data['spam_total'] );
	}

	public function test_export_csv_returns_csv_string(): void {
		$result = $this->journey->export( '7d', 'csv' );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 'csv', $result->data['format'] );
		$this->assertIsString( $result->data['payload'] );
		$this->assertStringContainsString( 'Date,Posts,Replies,New Users,Votes', $result->data['payload'] );
		$this->assertGreaterThan( 0, (int) $result->data['byte_length'] );
	}

	public function test_export_rejects_invalid_format(): void {
		$result = $this->journey->export( '7d', 'xml' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'format', strtolower( (string) $result->first_error() ) );
	}
}
