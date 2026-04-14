// @ts-check
/**
 * PRO-ANALYTICS-01 — Admin overview dashboard.
 *
 * Logs in as admin, navigates to the Pro analytics dashboard page,
 * and asserts the overview widget container renders.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-01 — Admin overview dashboard', () => {

	test.beforeEach( () => {
		// Ensure analytics extension is enabled.
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'admin sees the analytics overview dashboard', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		// The analytics wrapper should render.
		const wrapper = page.locator( '.wrap, .jt-analytics-dashboard, .jetonomy-analytics' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert a stats card or chart container is present.
		const statsWidget = page.locator(
			'.jt-analytics-card, .jt-analytics-overview, .jt-stat-card, [data-analytics-widget]'
		);
		await expect( statsWidget.first() ).toBeVisible( { timeout: 5000 } );

		// No PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
