// @ts-check
/**
 * GA13 — Settings: Permissions tab
 *
 * Visit the Jetonomy settings page with the permissions tab selected.
 * Assert that trust threshold fields and rate limit forms render correctly.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA13 — Settings permissions tab renders trust + rate limit forms', () => {

	test( 'trust threshold and rate limit forms are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=permissions' );
		metrics.start();

		// Trust threshold fields should render.
		const trustSection = page.locator( 'text=/trust.*threshold/i' ).first();
		await expect( trustSection ).toBeVisible( { timeout: 5000 } );

		// Rate limit fields should render.
		const rateLimitSection = page.locator( 'text=/rate.*limit/i' ).first();
		await expect( rateLimitSection ).toBeVisible( { timeout: 5000 } );

		// Verify at least one numeric input exists for thresholds.
		const thresholdInput = page.locator( 'input[type="number"][name*="trust"], input[type="number"][name*="threshold"]' ).first();
		await expect( thresholdInput ).toBeVisible( { timeout: 3000 } );

		// Verify at least one numeric input exists for rate limits.
		const rateLimitInput = page.locator( 'input[type="number"][name*="rate"], input[type="number"][name*="limit"]' ).first();
		await expect( rateLimitInput ).toBeVisible( { timeout: 3000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
