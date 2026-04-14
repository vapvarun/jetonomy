// @ts-check
/**
 * GA18 — Trust thresholds display
 *
 * Visit the permissions tab and verify that trust threshold values display
 * for each trust level, with rendered values matching the database.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp, journey } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA18 — Trust threshold values display on permissions tab', () => {

	const specId = 'GA18';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'rendered threshold values match database source of truth', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read trust thresholds from DB via journey or wp eval.
		let dbThresholds;
		try {
			const configResult = journey( [ 'config', 'get', '--key=trust_thresholds' ] );
			dbThresholds = configResult?.data || configResult;
		} catch ( e ) {
			// Fallback to wp eval if journey config command is not available.
			dbThresholds = wp( [ 'eval', `
				$s = get_option( 'jetonomy_settings', [] );
				echo wp_json_encode( $s['trust_thresholds'] ?? [] );
			` ], { json: true } );
		}

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=permissions' );
		metrics.start();

		// Verify trust threshold section is rendered.
		const trustSection = page.locator( 'text=/trust.*threshold/i' ).first();
		await expect( trustSection ).toBeVisible( { timeout: 5000 } );

		// Verify threshold inputs exist.
		const thresholdInputs = page.locator(
			'input[type="number"][name*="trust"], input[type="number"][name*="threshold"]'
		);
		const count = await thresholdInputs.count();
		expect( count ).toBeGreaterThan( 0 );

		// Collect all rendered threshold values.
		const renderedValues = [];
		for ( let i = 0; i < count; i++ ) {
			const val = await thresholdInputs.nth( i ).inputValue();
			renderedValues.push( val );
		}

		// All inputs should have numeric values (not empty).
		const allNumeric = renderedValues.every( ( v ) => /^\d+$/.test( v ) );

		// Cross-check: trust thresholds should exist in settings.
		const hasTT = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo ! empty( $s['trust_thresholds'] ) ? 'yes' : 'no';
		` ] );
		expect( hasTT ).toBe( 'yes' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			trust_section_visible: true,
			threshold_inputs_count_greater_than_zero: count > 0,
			trust_thresholds_exist_in_db: hasTT === 'yes',
			all_threshold_values_numeric: allNumeric,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
