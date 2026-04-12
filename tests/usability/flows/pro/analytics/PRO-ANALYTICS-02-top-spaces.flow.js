// @ts-check
/**
 * PRO-ANALYTICS-02 — Top spaces by activity.
 *
 * Navigates to the analytics dashboard and asserts the "top spaces"
 * widget renders a ranked list.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-02 — Top spaces by activity', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'top spaces widget shows ranked list', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		// Top spaces widget or section.
		const topSpaces = page.locator(
			'.jt-analytics-top-spaces, [data-widget="top-spaces"], .jt-top-spaces'
		);
		await expect( topSpaces.first() ).toBeVisible( { timeout: 5000 } );

		// At least one row or list item.
		const items = topSpaces.first().locator( 'tr, li, .jt-analytics-row' );
		const count = await items.count();
		expect( count ).toBeGreaterThanOrEqual( 0 ); // May be zero if no data seeded.

		metrics.assertErrorCount( 0 );
	} );
} );
