<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy_Pro\CLI\Journeys\Seo_Pro_Journey;

// Proactively load the Pro journey file. Pro's maybe_load_cli() only runs
// under WP-CLI (not PHPUnit), so autoloading wouldn't pick it up otherwise.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
if ( defined( 'WP_PLUGIN_DIR' ) && ! class_exists( Seo_Pro_Journey::class ) ) {
	$jt_pro_journey_path = WP_PLUGIN_DIR . '/jetonomy-pro/includes/cli/journeys/class-seo-pro-journey.php';
	if ( file_exists( $jt_pro_journey_path ) ) {
		require_once $jt_pro_journey_path;
	}
}
if ( ! class_exists( Seo_Pro_Journey::class ) ) {
	$jt_pro_journey_fallback = dirname( __DIR__, 5 ) . '/jetonomy-pro/includes/cli/journeys/class-seo-pro-journey.php';
	if ( file_exists( $jt_pro_journey_fallback ) ) {
		require_once $jt_pro_journey_fallback;
	}
}
// phpcs:enable WordPress.Files.FileName.InvalidClassFileName

/**
 * Integration tests for Seo_Pro_Journey against the live SEO Pro extension,
 * the core `jt_spaces.settings` JSON column, and the
 * `jetonomy_pro_seo_defaults` option.
 *
 * The Pro plugin must be active during the test run so the extension is
 * registered (the journey reads the extension instance from
 * `Jetonomy_Pro::get_extensions()`). Each test seeds a fresh space fixture
 * and tear_down() strips the `seo` override + restores the defaults option
 * so runs are independent.
 */
class SeoProJourneyTest extends WP_UnitTestCase {

	private Seo_Pro_Journey $journey;

	private int $space_id;

	/**
	 * Snapshot of the defaults option before each test, so tear_down can
	 * restore it verbatim even if a test deletes the option.
	 *
	 * @var mixed
	 */
	private $previous_defaults;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Seo_Pro_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Seo_Pro_Journey.' );
		}

		$this->journey = new Seo_Pro_Journey();

		$this->previous_defaults = get_option( 'jetonomy_pro_seo_defaults', null );
		delete_option( 'jetonomy_pro_seo_defaults' );

		$cat_id = Category::create(
			array(
				'name' => 'SEO Journey Cat',
				'slug' => 'seo-journey-cat-' . uniqid(),
			)
		);

		$this->space_id = (int) Space::create(
			array(
				'title'       => 'SEO Journey Space',
				'slug'        => 'seo-journey-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
				'description' => 'A fixture space for SEO journey tests.',
			)
		);
	}

	public function tear_down(): void {
		// Strip the seo override from the fixture space so the next test sees
		// a clean row. We scrub via wpdb rather than the journey to keep the
		// teardown independent of the code under test.
		global $wpdb;
		$table = \Jetonomy\table( 'spaces' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $this->space_id ) );
		if ( $row ) {
			$decoded = is_string( $row->settings ) ? json_decode( (string) $row->settings, true ) : array();
			if ( ! is_array( $decoded ) ) {
				$decoded = array();
			}
			unset( $decoded['seo'] );
			$wpdb->update(
				$table,
				array( 'settings' => wp_json_encode( $decoded ) ),
				array( 'id' => $this->space_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		if ( null === $this->previous_defaults ) {
			delete_option( 'jetonomy_pro_seo_defaults' );
		} else {
			update_option( 'jetonomy_pro_seo_defaults', $this->previous_defaults, false );
		}

		parent::tear_down();
	}

	public function test_get_space_seo_returns_defaults_for_unconfigured_space(): void {
		$result = $this->journey->get_space_seo( $this->space_id );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );

		$this->assertSame( $this->space_id, $result->data['space_id'] );
		$this->assertIsArray( $result->data['seo'] );

		$seo = $result->data['seo'];
		$this->assertArrayHasKey( 'meta_title', $seo );
		$this->assertArrayHasKey( 'meta_description', $seo );
		$this->assertArrayHasKey( 'og_image', $seo );
		$this->assertArrayHasKey( 'sitemap_priority', $seo );
		$this->assertArrayHasKey( 'exclude_sitemap', $seo );
		$this->assertArrayHasKey( 'noindex', $seo );
		$this->assertArrayHasKey( 'nofollow', $seo );
		$this->assertArrayHasKey( 'canonical_base', $seo );

		$this->assertSame( '', $seo['meta_title'] );
		$this->assertFalse( $seo['noindex'] );
		$this->assertFalse( $seo['nofollow'] );
		$this->assertFalse( $seo['exclude_sitemap'] );
	}

	public function test_update_space_seo_persists_whitelisted_fields(): void {
		$result = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'meta_title'       => '{space_name} | {site_name}',
				'meta_description' => 'Fixture description.',
				'noindex'          => true,
				'sitemap_priority' => '0.7',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( array( 'meta_title', 'meta_description', 'noindex', 'sitemap_priority' ), $result->data['updated'] );

		$seo = $result->data['seo'];
		$this->assertSame( '{space_name} | {site_name}', $seo['meta_title'] );
		$this->assertSame( 'Fixture description.', $seo['meta_description'] );
		$this->assertTrue( $seo['noindex'] );
		$this->assertSame( '0.7', $seo['sitemap_priority'] );

		// Re-read through the journey to confirm persistence.
		$reread = $this->journey->get_space_seo( $this->space_id );
		$this->assertTrue( $reread->is_success() );
		$this->assertSame( '{space_name} | {site_name}', $reread->data['seo']['meta_title'] );
		$this->assertTrue( $reread->data['seo']['noindex'] );
	}

	public function test_update_space_seo_rejects_unknown_keys(): void {
		$result = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'meta_title'   => 'Allowed.',
				'random_field' => 'bogus',
				'another_fake' => 'x',
			)
		);

		$this->assertFalse( $result->is_success() );
		$error = strtolower( (string) $result->first_error() );
		$this->assertStringContainsString( 'unknown', $error );
		$this->assertStringContainsString( 'random_field', $error );
	}

	public function test_update_space_seo_rejects_invalid_schema_type(): void {
		// schema_type is NOT a per-space field on the extension, so passing
		// it via the update path must be rejected as an unknown key.
		$result = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'schema_type' => 'NotAType',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'unknown', strtolower( (string) $result->first_error() ) );

		// Separately, sitemap_priority has an explicit allowlist and must
		// reject values outside it — same class of validation.
		$bad_priority = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'sitemap_priority' => '99',
			)
		);
		$this->assertFalse( $bad_priority->is_success() );
		$this->assertStringContainsString( 'sitemap_priority', strtolower( (string) $bad_priority->first_error() ) );
	}

	public function test_update_space_seo_rejects_invalid_canonical_url(): void {
		$result = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'canonical_base' => 'not a url',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'canonical_base', strtolower( (string) $result->first_error() ) );

		// Non-string booleans for a bool field must also be rejected.
		$bad_bool = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'noindex' => 'maybe',
			)
		);
		$this->assertFalse( $bad_bool->is_success() );
		$this->assertStringContainsString( 'noindex', strtolower( (string) $bad_bool->first_error() ) );
	}

	public function test_reset_space_seo_clears_overrides(): void {
		// Seed some overrides first.
		$update = $this->journey->update_space_seo(
			$this->space_id,
			array(
				'meta_title' => 'Before reset.',
				'noindex'    => true,
			)
		);
		$this->assertTrue( $update->is_success(), implode( '; ', $update->errors ) );

		$reset = $this->journey->reset_space_seo( $this->space_id );
		$this->assertTrue( $reset->is_success(), implode( '; ', $reset->errors ) );
		$this->assertTrue( (bool) $reset->data['cleared'] );

		$after = $this->journey->get_space_seo( $this->space_id );
		$this->assertTrue( $after->is_success() );
		$this->assertSame( '', $after->data['seo']['meta_title'] );
		$this->assertFalse( $after->data['seo']['noindex'] );

		// Second reset is a no-op but still succeeds with cleared=false.
		$second = $this->journey->reset_space_seo( $this->space_id );
		$this->assertTrue( $second->is_success() );
		$this->assertFalse( (bool) $second->data['cleared'] );
	}

	public function test_preview_space_seo_returns_meta_tags(): void {
		$this->journey->update_space_seo(
			$this->space_id,
			array(
				'meta_title'       => '{space_name} | {site_name}',
				'meta_description' => 'Preview description.',
				'noindex'          => true,
			)
		);

		$result = $this->journey->preview_space_seo( $this->space_id );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'title', $result->data );
		$this->assertArrayHasKey( 'description', $result->data );
		$this->assertArrayHasKey( 'canonical', $result->data );
		$this->assertArrayHasKey( 'meta_tags', $result->data );
		$this->assertArrayHasKey( 'og_tags', $result->data );
		$this->assertArrayHasKey( 'twitter_tags', $result->data );
		$this->assertArrayHasKey( 'schema', $result->data );

		$this->assertStringContainsString( 'SEO Journey Space', $result->data['title'] );
		$this->assertSame( 'Preview description.', $result->data['description'] );
		$this->assertContains( 'noindex', $result->data['robots'] );

		// Schema.org JSON-LD shape mirrors the extension's CollectionPage.
		$schema = $result->data['schema'];
		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'CollectionPage', $schema['@type'] );
		$this->assertSame( 'SEO Journey Space', $schema['name'] );
	}

	public function test_get_global_defaults_returns_shape(): void {
		$result = $this->journey->get_global_defaults();

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'defaults', $result->data );
		$defaults = $result->data['defaults'];

		$this->assertArrayHasKey( 'title_format', $defaults );
		$this->assertArrayHasKey( 'description', $defaults );
		$this->assertArrayHasKey( 'og_image', $defaults );
		$this->assertSame( '{space} — {site}', $defaults['title_format'] );
	}

	public function test_update_global_defaults_persists(): void {
		$result = $this->journey->update_global_defaults(
			array(
				'title_format' => '{site} :: {space}',
				'description'  => 'Site-wide fallback description.',
				'og_image'     => 'https://example.test/default.png',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( '{site} :: {space}', $result->data['defaults']['title_format'] );
		$this->assertSame( 'https://example.test/default.png', $result->data['defaults']['og_image'] );

		// Re-read through the journey.
		$reread = $this->journey->get_global_defaults();
		$this->assertTrue( $reread->is_success() );
		$this->assertSame( '{site} :: {space}', $reread->data['defaults']['title_format'] );

		// Unknown field must be rejected.
		$bad = $this->journey->update_global_defaults( array( 'nope' => 'x' ) );
		$this->assertFalse( $bad->is_success() );
		$this->assertStringContainsString( 'unknown', strtolower( (string) $bad->first_error() ) );

		// Bad URL must be rejected.
		$bad_url = $this->journey->update_global_defaults( array( 'og_image' => 'not a url' ) );
		$this->assertFalse( $bad_url->is_success() );
		$this->assertStringContainsString( 'og_image', strtolower( (string) $bad_url->first_error() ) );
	}

	/**
	 * Serve a canned page body instead of making a real request.
	 *
	 * validate_schema() reads what the SITE emits, which is the whole point of
	 * it - but there is no HTTP server in a unit run, so the fetch is
	 * short-circuited here. What is under test is the parsing and the
	 * one-primary-entity rule, and both are exercised faithfully by feeding
	 * the parser the same shape of markup a real page produces.
	 *
	 * @param string $body Page HTML to return.
	 * @return callable The filter, so the caller can remove it.
	 */
	private function stub_page( string $body ): callable {
		$filter = static function () use ( $body ) {
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		return $filter;
	}

	/**
	 * One ld+json island, wrapped the way a rendered page wraps it.
	 *
	 * @param array<int,array<string,mixed>> $entities Entities to emit.
	 */
	private function ld_json_page( array $entities ): string {
		$scripts = '';
		foreach ( $entities as $entity ) {
			$scripts .= '<script type="application/ld+json">' . wp_json_encode( $entity ) . '</script>';
		}

		return '<html><head>' . $scripts . '</head><body>x</body></html>';
	}

	public function test_validate_schema_accepts_a_page_with_one_primary_entity(): void {
		$filter = $this->stub_page(
			$this->ld_json_page(
				array(
					// A BreadcrumbList alongside the primary entity is correct
					// and must NOT be counted as a competing primary.
					array(
						'@context' => 'https://schema.org',
						'@type'    => 'BreadcrumbList',
					),
					array(
						'@context' => 'https://schema.org',
						'@type'    => 'CollectionPage',
						'name'     => 'A space',
						'url'      => 'https://example.test/community/s/a/',
					),
				)
			)
		);

		$result = $this->journey->validate_schema( $this->space_id );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( (bool) $result->data['valid'], implode( '; ', (array) $result->data['errors'] ) );
		$this->assertSame( array(), $result->data['errors'] );
		$this->assertSame( 'CollectionPage', $result->data['schema']['@type'] );
	}

	/**
	 * The assertion this whole rewrite exists for.
	 *
	 * Basecamp 10212231937 shipped two competing primary entities on every Pro
	 * post page - a Q&A topic claiming to be both QAPage and
	 * DiscussionForumPosting - and the previous version of validate_schema()
	 * passed throughout, because it asserted against an array the test itself
	 * built. If this case ever goes green, the check has gone blind again.
	 */
	public function test_validate_schema_rejects_two_competing_primary_entities(): void {
		$filter = $this->stub_page(
			$this->ld_json_page(
				array(
					array(
						'@context' => 'https://schema.org',
						'@type'    => 'QAPage',
						'name'     => 'A question',
						'url'      => 'https://example.test/community/s/a/t/q/',
					),
					array(
						'@context' => 'https://schema.org',
						'@type'    => 'DiscussionForumPosting',
						'name'     => 'The same page, disagreeing with itself',
						'url'      => 'https://example.test/community/s/a/t/q/',
					),
				)
			)
		);

		$result = $this->journey->validate_schema( $this->space_id );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertFalse( (bool) $result->data['valid'] );
		$this->assertStringContainsString( 'competing primary entities', implode( ' ', (array) $result->data['errors'] ) );
	}

	public function test_validate_schema_flags_a_malformed_json_ld_block(): void {
		// A block that does not parse is invisible to search engines, so
		// skipping it quietly would hide the failure being checked for.
		$filter = $this->stub_page( '<html><head><script type="application/ld+json">{not json</script></head><body>x</body></html>' );

		$result = $this->journey->validate_schema( $this->space_id );
		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertFalse( (bool) $result->data['valid'] );
		$this->assertStringContainsString( 'does not parse', implode( ' ', (array) $result->data['errors'] ) );
	}

	public function test_validate_schema_rejects_a_bad_space_id(): void {
		$bad = $this->journey->validate_schema( 0 );
		$this->assertFalse( $bad->is_success() );
		$this->assertStringContainsString( 'space_id', strtolower( (string) $bad->first_error() ) );
	}
}
