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
				'accent_color'   => '#ff6a00',
			),
			false
		);

		$result = $this->journey->get_settings();

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertArrayHasKey( 'settings', $result->data );
		$this->assertSame( 'Acme Forum', $result->data['settings']['community_name'] );
		$this->assertSame( '#ff6a00', $result->data['settings']['accent_color'] );
		// Defaults should be filled in for absent keys.
		$this->assertSame( '', $result->data['settings']['custom_css'] );
	}

	public function test_update_settings_whitelists_fields(): void {
		$result = $this->journey->update_settings(
			array(
				'community_name' => 'Beta Community',
				'footer_text'    => 'Powered by Beta',
				'accent_color'   => '#1a2b3c',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertContains( 'community_name', $result->data['updated_keys'] );
		$this->assertContains( 'footer_text', $result->data['updated_keys'] );
		$this->assertContains( 'accent_color', $result->data['updated_keys'] );

		$stored = get_option( 'jetonomy_pro_white_label' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Beta Community', $stored['community_name'] );
		$this->assertSame( 'Powered by Beta', $stored['footer_text'] );
		$this->assertSame( '#1a2b3c', $stored['accent_color'] );
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

	public function test_update_settings_rejects_malformed_hex_color(): void {
		$result = $this->journey->update_settings(
			array( 'accent_color' => 'not-a-color' )
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'accent_color', strtolower( (string) $result->first_error() ) );
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
				'accent_color'   => '#abcdef',
				'logo_url'       => 'https://example.test/logo.png',
			),
			false
		);

		$result = $this->journey->preview_branding();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Preview Land', $result->data['community_name'] );
		$this->assertSame( 'Preview Admin', $result->data['admin_menu_label'] );
		$this->assertSame( '(c) Preview', $result->data['footer_html'] );
		$this->assertSame( '#abcdef', $result->data['accent_color'] );
		$this->assertSame( 'https://example.test/logo.png', $result->data['logo_url'] );
		$this->assertArrayHasKey( 'admin_menu_icon', $result->data );
		$this->assertArrayHasKey( 'custom_css_bytes', $result->data );
	}

	public function test_validate_css_accepts_clean_css(): void {
		$css    = ".jt-app { color: #111; }\n.jt-card { padding: 8px; }";
		$result = $this->journey->validate_css( $css );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( (bool) $result->data['valid'] );
		$this->assertSame( array(), $result->data['errors'] );
		$this->assertSame( strlen( $css ), (int) $result->data['size_bytes'] );
	}

	public function test_validate_css_rejects_script_tags(): void {
		$css    = 'body{color:red}<script>alert(1)</script>';
		$result = $this->journey->validate_css( $css );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( (bool) $result->data['valid'] );
		$this->assertNotEmpty( $result->data['errors'] );
		$joined = strtolower( implode( ' ', $result->data['errors'] ) );
		$this->assertStringContainsString( 'script', $joined );
	}

	public function test_validate_css_rejects_oversized_css(): void {
		$css    = str_repeat( 'a', 60000 );
		$result = $this->journey->validate_css( $css );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( (bool) $result->data['valid'] );
		$joined = strtolower( implode( ' ', $result->data['errors'] ) );
		$this->assertStringContainsString( 'max', $joined );
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
				'accent_color'   => '#123456',
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
		$this->assertSame( '#123456', $stored['accent_color'] );
	}
}
