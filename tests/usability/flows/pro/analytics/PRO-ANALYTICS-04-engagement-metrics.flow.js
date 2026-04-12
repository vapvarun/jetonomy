// @ts-check
/**
 * PRO-ANALYTICS-04 — Engagement metrics.
 *
 * Verifies the engagement metrics section (views, votes, reactions)
 * is visible on the analytics dashboard.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-04 — Engagement metrics', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'engagement metrics section renders', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		const engagement = page.locator(
			'.jt-analytics-engagement, [data-widget="engagement"], .jt-engagement-metrics'
		);
		await expect( engagement.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
