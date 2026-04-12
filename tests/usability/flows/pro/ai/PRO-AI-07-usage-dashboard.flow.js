// @ts-check
/**
 * PRO-AI-07 — Admin view usage dashboard.
 *
 * Navigates to the AI usage dashboard in admin and verifies the
 * usage stats section renders.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-AI-07 — Admin view usage dashboard', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test( 'AI usage dashboard renders', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-settings&tab=ai' );
		metrics.start();

		// Usage section or stats display.
		const usage = page.locator(
			'.jt-ai-usage, [data-section="ai-usage"], .jt-ai-stats, .jt-ai-dashboard'
		);
		await expect( usage.first() ).toBeVisible( { timeout: 5000 } );

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
