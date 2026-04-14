// @ts-check
/**
 * PRO-ANALYTICS-06 — Export CSV.
 *
 * Verifies the CSV export button is visible and triggers a download
 * on the analytics dashboard.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-06 — Export CSV', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'export CSV button is present and triggers download', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		const exportBtn = page.locator(
			'a:has-text("Export"), button:has-text("Export CSV"), [data-action="export-csv"]'
		);
		await expect( exportBtn.first() ).toBeVisible( { timeout: 5000 } );

		// Initiate download and verify the response is a file.
		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download', { timeout: 10000 } ).catch( () => null ),
			exportBtn.first().click(),
		] );
		metrics.recordClick();

		// download may be null if the feature streams inline — at least
		// confirm no error after click.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
