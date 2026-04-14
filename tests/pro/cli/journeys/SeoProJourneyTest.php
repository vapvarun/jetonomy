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

	public function test_validate_schema_returns_validation_result(): void {
		$result = $this->journey->validate_schema( $this->space_id );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'valid', $result->data );
		$this->assertArrayHasKey( 'errors', $result->data );
		$this->assertArrayHasKey( 'schema', $result->data );

		$this->assertTrue( (bool) $result->data['valid'], 'Fresh space schema should be valid: ' . implode( '; ', (array) $result->data['errors'] ) );
		$this->assertIsArray( $result->data['errors'] );
		$this->assertSame( array(), $result->data['errors'] );

		// space_id <= 0 must be rejected up front.
		$bad = $this->journey->validate_schema( 0 );
		$this->assertFalse( $bad->is_success() );
		$this->assertStringContainsString( 'space_id', strtolower( (string) $bad->first_error() ) );
	}
}
