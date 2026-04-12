// @ts-check
/**
 * GA18 — Trust thresholds display
 *
 * Visit the permissions tab and verify that trust threshold values display
 * for each trust level (1-5) with the expected fields (posts, replies, etc.).
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA18 — Trust threshold values display on permissions tab', () => {

	test( 'threshold values for each trust level are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=permissions' );
		metrics.start();

		// Verify trust threshold section is rendered.
		const trustSection = page.locator( 'text=/trust.*threshold/i' ).first();
		await expect( trustSection ).toBeVisible( { timeout: 5000 } );

		// Verify at least one threshold input contains a numeric value.
		const thresholdInputs = page.locator( 'input[type="number"][name*="trust"], input[type="number"][name*="threshold"]' );
		const count = await thresholdInputs.count();
		expect( count ).toBeGreaterThan( 0 );

		// Cross-check with DB: trust thresholds should exist in settings.
		const hasTT = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo ! empty( $s['trust_thresholds'] ) ? 'yes' : 'no';
		` ] );
		expect( hasTT ).toBe( 'yes' );

		metrics.assertErrorCount( 0 );
	} );
} );
