// @ts-check
/**
 * PRO-ANALYTICS-07 — Custom date range.
 *
 * Verifies the date range picker is present and selecting a custom
 * range reloads the analytics data without error.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-07 — Custom date range', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'date range picker filters data', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		// Date picker inputs.
		const dateFrom = page.locator(
			'input[name*="date_from"], input[name*="start_date"], [data-range="from"]'
		);
		const dateTo = page.locator(
			'input[name*="date_to"], input[name*="end_date"], [data-range="to"]'
		);
		await expect( dateFrom.first() ).toBeVisible( { timeout: 5000 } );

		// Fill a 7-day window.
		await dateFrom.first().fill( '2026-04-01' );
		await dateTo.first().fill( '2026-04-07' );
		metrics.recordClick();

		// Submit/apply filter.
		const applyBtn = page.locator(
			'button:has-text("Apply"), button:has-text("Filter"), input[type="submit"]'
		);
		if ( await applyBtn.count() > 0 ) {
			await applyBtn.first().click();
			metrics.recordClick();
		}

		// No PHP fatal after filter.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
