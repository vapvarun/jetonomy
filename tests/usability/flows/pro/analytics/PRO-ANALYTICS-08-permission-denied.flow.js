// @ts-check
/**
 * PRO-ANALYTICS-08 — Permission denied for non-admin.
 *
 * Logs in as a subscriber/regular user and verifies they cannot
 * access the analytics dashboard.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-ANALYTICS-08 — Permission denied non-admin', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'analytics' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'analytics' ] );
		}
	} );

	test( 'non-admin cannot access analytics dashboard', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Log in as alice (non-admin, user_id=3).
		await autoLogin( page, 'alice', '/wp-admin/admin.php?page=jetonomy-pro-analytics' );
		metrics.start();

		// WP should redirect to dashboard or show access denied.
		const bodyText = await page.locator( 'body' ).textContent();
		const isDenied = bodyText.includes( 'not have sufficient permissions' )
			|| bodyText.includes( 'cheatin' )
			|| bodyText.includes( 'Sorry' )
			|| ! page.url().includes( 'jetonomy-pro-analytics' );

		expect( isDenied ).toBe( true );

		metrics.assertErrorCount( 0 );
	} );
} );
