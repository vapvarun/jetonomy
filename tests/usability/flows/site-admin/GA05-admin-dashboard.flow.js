// @ts-check
/**
 * GA05 — Admin dashboard.
 *
 * Visits the Jetonomy admin dashboard page and asserts dashboard widgets
 * render correctly.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA05 — View admin dashboard', () => {

	test( 'dashboard page renders with widgets', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy' );
		metrics.start();

		// Assert the dashboard wrapper renders.
		const dashboard = page.locator(
			'.jetonomy-dashboard, .jetonomy-admin-dashboard, #jetonomy-dashboard, .wrap'
		);
		await expect( dashboard.first() ).toBeVisible( { timeout: 5000 } );

		// Assert at least one dashboard widget/card renders.
		const widget = page.locator(
			'.jetonomy-dashboard-widget, .jetonomy-stat-card, .postbox, .jetonomy-card'
		);
		await expect( widget.first() ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
