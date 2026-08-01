<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\White_Label_Journey;

// Proactively load the Pro journey file. Pro's maybe_load_cli() only runs
// under WP-CLI (not PHPUnit), so autoloading wouldn't pick it up otherwise.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
if ( defined( 'WP_PLUGIN_DIR' ) && ! class_exists( White_Label_Journey::class ) ) {
	$jt_pro_journey_path = WP_PLUGIN_DIR . '/jetonomy-pro/includes/cli/journeys/class-white-label-journey.php';
	if ( file_exists( $jt_pro_journey_path ) ) {
		require_once $jt_pro_journey_path;
	}
}
if ( ! class_exists( White_Label_Journey::class ) ) {
	$jt_pro_journey_fallback = dirname( __DIR__, 5 ) . '/jetonomy-pro/includes/cli/journeys/class-white-label-journey.php';
	if ( file_exists( $jt_pro_journey_fallback ) ) {
		require_once $jt_pro_journey_fallback;
	}
}
// phpcs:enable WordPress.Files.FileName.InvalidClassFileName

/**
 * Integration tests for White_Label_Journey against the
 * `jetonomy_pro_white_label` option. The Pro plugin must be active during the
 * test run so the option shape stays aligned with the extension's expectations.
 *
 * Each test snapshots the existing option in set_up() and restores it in
 * tear_down() so a site with live branding settings is not clobbered.
 *
 * SCOPE NOTE. White Label is backend-only. It renames and re-labels wp-admin;
 * it does not style the frontend. Commit ae949a2 (2026-07-04) deliberately
 * dropped every frontend/email/styling field, leaving exactly four settings:
 * community_name, footer_text, admin_label, admin_icon.
 *
 * This file was written before that decision and went unrevised, so it kept
 * asserting an `accent_color` setting and a `validate_css()` method - neither
 * of which has existed since. That produced seven failures which read like
 * product bugs and were carried as "pre-existing" noise across releases,
 * making the suite worse than useless for this extension: nobody could tell a
 * real regression from the standing red.
 *
 * The tests below assert the shipped scope instead, and two of them
 * (test_styling_fields_are_rejected, test_exposes_no_css_surface) exist
 * specifically to pin the scope decision so styling cannot creep back in
 * without someone consciously reversing it.
 */
class WhiteLabelJourneyTest extends WP_UnitTestCase {

	private White_Label_Journey $journey;

	/**
	 * Snapshot of the pre-test option value, restored in tear_down().
	 *
	 * @var mixed
	 */
	private $snapshot;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( White_Label_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise White_Label_Journey.' );
		}

		$this->journey  = new White_Label_Journey();
		$this->snapshot = get_option( 'jetonomy_pro_white_label', null );

		// Start each test with a clean slate so assertions are deterministic.
		delete_option( 'jetonomy_pro_white_label' );
	}

	public function tear_down(): void {
		if ( null === $this->snapshot ) {
			delete_option( 'jetonomy_pro_white_label' );
		} else {
			update_option( 'jetonomy_pro_white_label', $this->snapshot, false );
		}
		parent::tear_down();
	}

	public function test_get_settings_returns_current_option(): void {
		update_option(
			'jetonomy_pro_white_label',
			array(
				'community_name' => 'Acme Forum',
				'admin_label'    => 'Acme HQ',
			),
			false
		);

		$result = $this->journey->get_settings();

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'settings', $result->data );
		$this->assertSame( 'Acme Forum', $result->data['settings']['community_name'] );
		$this->assertSame( 'Acme HQ', $result->data['settings']['admin_label'] );
		// Defaults are filled in for absent keys, so callers always get a
		// complete shape and never have to guard on missing indexes.
		$this->assertSame( '', $result->data['settings']['footer_text'] );
		$this->assertSame( '', $result->data['settings']['admin_icon'] );
	}

	public function test_update_settings_whitelists_fields(): void {
		$result = $this->journey->update_settings(
			array(
				'community_name' => 'Beta Community',
				'footer_text'    => 'Powered by Beta',
				'admin_label'    => 'Beta HQ',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertContains( 'community_name', $result->data['updated_keys'] );
		$this->assertContains( 'footer_text', $result->data['updated_keys'] );
		$this->assertContains( 'admin_label', $result->data['updated_keys'] );

		$stored = get_option( 'jetonomy_pro_white_label' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Beta Community', $stored['community_name'] );
		$this->assertSame( 'Powered by Beta', $stored['footer_text'] );
		$this->assertSame( 'Beta HQ', $stored['admin_label'] );
	}

	public function test_update_settings_rejects_unknown_keys(): void {
		$result = $this->journey->update_settings(
			array(
				'community_name' => 'OK',
				'nefarious'      => 'value',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'nefarious', (string) $result->first_error() );
		// Nothing should have been written because the patch failed whole.
		$this->assertFalse( get_option( 'jetonomy_pro_white_label', false ) );
	}

	/**
	 * Styling is not part of White Label and a patch carrying it must fail.
	 *
	 * Pins the ae949a2 scope decision from the write side. If somebody adds a
	 * colour or CSS field to ALLOWED_FIELDS without reversing that decision
	 * deliberately, this goes red rather than the change landing unnoticed.
	 *
	 * @dataProvider dropped_styling_field_provider
	 */
	public function test_styling_fields_are_rejected( string $field, string $value ): void {
		$result = $this->journey->update_settings( array( $field => $value ) );

		$this->assertFalse(
			$result->is_success(),
			sprintf( '%s is a frontend styling concern and White Label is backend-only', $field )
		);
		$this->assertStringContainsString( $field, strtolower( (string) $result->first_error() ) );
		$this->assertFalse(
			get_option( 'jetonomy_pro_white_label', false ),
			'a rejected patch must write nothing at all'
		);
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function dropped_styling_field_provider(): array {
		return array(
			'accent colour' => array( 'accent_color', '#1a2b3c' ),
			'custom css'    => array( 'custom_css', '.jt-app{color:red}' ),
			'logo'          => array( 'logo_url', 'https://example.test/logo.png' ),
		);
	}

	public function test_reset_settings_deletes_option(): void {
		update_option(
			'jetonomy_pro_white_label',
			array( 'community_name' => 'Will Be Gone' ),
			false
		);

		$result = $this->journey->reset_settings();

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( (bool) $result->data['reset'] );
		$this->assertFalse( get_option( 'jetonomy_pro_white_label', false ) );
	}

	public function test_preview_branding_returns_shape(): void {
		update_option(
			'jetonomy_pro_white_label',
			array(
				'community_name' => 'Preview Land',
				'admin_label'    => 'Preview Admin',
				'footer_text'    => '(c) Preview',
				'admin_icon'     => 'dashicons-groups',
			),
			false
		);

		$result = $this->journey->preview_branding();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Preview Land', $result->data['community_name'] );
		$this->assertSame( 'Preview Admin', $result->data['admin_menu_label'] );
		$this->assertSame( '(c) Preview', $result->data['footer_html'] );
		$this->assertSame( 'dashicons-groups', $result->data['admin_menu_icon'] );
	}

	/**
	 * With nothing configured, preview falls back to the site's own identity
	 * rather than showing blanks - the branding an owner sees before they have
	 * typed anything is what wp-admin already says.
	 */
	public function test_preview_branding_falls_back_to_site_identity(): void {
		$result = $this->journey->preview_branding();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( get_bloginfo( 'name' ), $result->data['community_name'] );
		// Admin label defaults to the community name, not to an empty menu.
		$this->assertSame( get_bloginfo( 'name' ), $result->data['admin_menu_label'] );
	}

	/**
	 * White Label exposes no CSS surface at all.
	 *
	 * The other half of the ae949a2 pin. `validate_css()` existed when this
	 * extension styled the frontend; the scope-back removed the feature and
	 * the method with it. Asserting its absence keeps that explicit - a future
	 * reader finds a recorded decision here instead of wondering whether CSS
	 * validation was meant to exist and got lost.
	 */
	public function test_exposes_no_css_surface(): void {
		$this->assertFalse(
			method_exists( $this->journey, 'validate_css' ),
			'White Label is backend-only since ae949a2; it must not accept custom CSS'
		);

		$settings = $this->journey->get_settings()->data['settings'];
		foreach ( array( 'custom_css', 'accent_color', 'logo_url' ) as $dropped ) {
			$this->assertArrayNotHasKey(
				$dropped,
				$settings,
				sprintf( '%s is a frontend styling concern and must not be in the branding option', $dropped )
			);
		}
	}

	public function test_export_settings_includes_version(): void {
		update_option(
			'jetonomy_pro_white_label',
			array( 'community_name' => 'Exportable' ),
			false
		);

		$result = $this->journey->export_settings();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'payload', $result->data );
		$payload = $result->data['payload'];
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'version', $payload );
		$this->assertIsInt( $payload['version'] );
		$this->assertSame( 'Exportable', $payload['settings']['community_name'] );
	}

	public function test_import_settings_rejects_unversioned_payload(): void {
		$result = $this->journey->import_settings(
			array(
				'settings' => array( 'community_name' => 'No Version' ),
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'version', strtolower( (string) $result->first_error() ) );
	}

	public function test_import_settings_applies_valid_payload(): void {
		// Export first, then re-import the same payload.
		update_option(
			'jetonomy_pro_white_label',
			array(
				'community_name' => 'Round Trip',
				'admin_label'    => 'Round Trip HQ',
				'footer_text'    => '(c) Round Trip',
			),
			false
		);

		$export = $this->journey->export_settings();
		$this->assertTrue( $export->is_success() );
		$payload = $export->data['payload'];

		// Wipe and import.
		delete_option( 'jetonomy_pro_white_label' );
		$result = $this->journey->import_settings( $payload );

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( (bool) $result->data['imported'] );

		$stored = get_option( 'jetonomy_pro_white_label' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Round Trip', $stored['community_name'] );
		$this->assertSame( 'Round Trip HQ', $stored['admin_label'] );
		$this->assertSame( '(c) Round Trip', $stored['footer_text'] );
	}

	/**
	 * An export taken from a site that still had styling settings - a backup
	 * made before ae949a2, or one hand-edited - imports its supported keys and
	 * drops the rest, rather than failing the whole restore.
	 *
	 * Import routes each key through update_settings(), which rejects unknown
	 * keys outright, so this pins that the payload is FILTERED to the allowed
	 * set first. Without that, every pre-scope-back backup would be
	 * un-restorable.
	 */
	public function test_import_drops_legacy_styling_keys_instead_of_failing(): void {
		$result = $this->journey->import_settings(
			array(
				'version'  => 1,
				'settings' => array(
					'community_name' => 'Legacy Site',
					'accent_color'   => '#ff6a00',
					'custom_css'     => '.jt-app{color:red}',
				),
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );

		$stored = get_option( 'jetonomy_pro_white_label' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Legacy Site', $stored['community_name'] );
		$this->assertArrayNotHasKey( 'accent_color', $stored );
		$this->assertArrayNotHasKey( 'custom_css', $stored );
	}
}
