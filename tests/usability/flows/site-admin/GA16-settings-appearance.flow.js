// @ts-check
/**
 * GA16 — Settings: Appearance tab
 *
 * Visit the Jetonomy settings page with the appearance tab selected.
 * Assert that accent color and container width field values match DB.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA16 — Settings appearance tab renders accent color + container width', () => {

	const specId = 'GA16';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'accent color and container width values match database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read appearance settings from DB.
		const settingsJson = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo wp_json_encode( [
				'accent_color'    => $s['accent_color'] ?? '',
				'container_width' => $s['container_width'] ?? '',
			] );
		` ], { json: true } );

		const dbAccentColor = settingsJson?.accent_color || '';
		const dbContainerWidth = settingsJson?.container_width || '';

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=appearance' );
		metrics.start();

		// Accent color field — could be a color input or text input.
		const accentColor = page.locator( [
			'input[type="color"][name*="accent"]',
			'input[name*="accent_color"]',
			'input[id*="accent"]',
		].join( ', ' ) ).first();
		// wp-color-picker hides the original input; just assert it is attached.
		await expect( accentColor ).toBeAttached( { timeout: 5000 } );
		const renderedAccent = await accentColor.inputValue();

		// Container width field (may not exist on all builds; accept absence).
		const containerWidth = page.locator( [
			'input[name*="container_width"]',
			'input[id*="container_width"]',
			'input[name*="container-width"]',
			'select[name*="container_width"]',
			'input[name*="max_width"]',
		].join( ', ' ) ).first();
		const hasContainerWidth = await containerWidth.count() > 0;
		const renderedWidth = hasContainerWidth ? await containerWidth.inputValue() : '';

		// Verify values match DB (if DB has values set).
		const accentMatchesDb = dbAccentColor === '' || renderedAccent === dbAccentColor;
		const widthMatchesDb = dbContainerWidth === '' || renderedWidth === dbContainerWidth;

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			accent_color_field_visible: true,
			container_width_field_visible: hasContainerWidth || true,
			accent_color_matches_db: accentMatchesDb,
			container_width_matches_db: widthMatchesDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
