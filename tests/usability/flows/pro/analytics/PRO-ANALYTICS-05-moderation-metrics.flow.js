// @ts-check
/**
 * PRO-ANALYTICS-05 — Moderation metrics.
 *
 * Verifies the moderation metrics section (flags, bans, held content)
 * is visible on the analytics dashboard.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-05 — Moderation metrics', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'moderation metrics section renders', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		const modMetrics = page.locator(
			'.jt-analytics-moderation, [data-widget="moderation"], .jt-moderation-metrics'
		);
		await expect( modMetrics.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
