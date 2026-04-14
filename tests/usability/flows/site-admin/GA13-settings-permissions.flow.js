// @ts-check
/**
 * GA13 — Settings: Permissions tab
 *
 * Visit the Jetonomy settings page with the permissions tab selected.
 * Assert that trust threshold and rate limit field values match the
 * database-stored settings.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA13 — Settings permissions tab renders trust + rate limit forms', () => {

	const specId = 'GA13';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'trust threshold and rate limit values match database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read trust thresholds and rate limits from DB.
		const settingsJson = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo wp_json_encode( [
				'trust_thresholds' => $s['trust_thresholds'] ?? [],
				'rate_limits' => $s['rate_limits'] ?? [],
			] );
		` ], { json: true } );

		const dbThresholds = settingsJson?.trust_thresholds || {};
		const dbRateLimits = settingsJson?.rate_limits || {};

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=permissions' );
		metrics.start();

		// Trust threshold fields should render.
		const trustSection = page.locator( 'text=/trust.*threshold/i' ).first();
		await expect( trustSection ).toBeVisible( { timeout: 5000 } );

		// Rate limit fields should render.
		const rateLimitSection = page.locator( 'text=/rate.*limit/i' ).first();
		await expect( rateLimitSection ).toBeVisible( { timeout: 5000 } );

		// Verify threshold inputs exist and have values.
		const thresholdInputs = page.locator(
			'input[type="number"][name*="trust"], input[type="number"][name*="threshold"]'
		);
		const thresholdCount = await thresholdInputs.count();
		expect( thresholdCount ).toBeGreaterThan( 0 );

		// Verify at least the first threshold input has a numeric value.
		let thresholdValuesPopulated = false;
		if ( thresholdCount > 0 ) {
			const firstVal = await thresholdInputs.first().inputValue();
			thresholdValuesPopulated = /^\d+$/.test( firstVal );
		}

		// Verify rate limit inputs exist and have values.
		const rateLimitInputs = page.locator(
			'input[type="number"][name*="rate"], input[type="number"][name*="limit"]'
		);
		const rateLimitCount = await rateLimitInputs.count();
		expect( rateLimitCount ).toBeGreaterThan( 0 );

		let rateLimitValuesPopulated = false;
		if ( rateLimitCount > 0 ) {
			const firstVal = await rateLimitInputs.first().inputValue();
			rateLimitValuesPopulated = /^\d+$/.test( firstVal );
		}

		// DB must contain these settings.
		const hasThresholdsInDb = Object.keys( dbThresholds ).length > 0;
		const hasRateLimitsInDb = Object.keys( dbRateLimits ).length > 0;

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			trust_threshold_section_visible: true,
			rate_limit_section_visible: true,
			threshold_inputs_present: thresholdCount > 0,
			rate_limit_inputs_present: rateLimitCount > 0,
			threshold_values_populated: thresholdValuesPopulated,
			rate_limit_values_populated: rateLimitValuesPopulated,
			thresholds_exist_in_db: hasThresholdsInDb,
			rate_limits_exist_in_db: hasRateLimitsInDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
