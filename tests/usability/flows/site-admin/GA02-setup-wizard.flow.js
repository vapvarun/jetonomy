// @ts-check
/**
 * GA02 — Setup wizard.
 *
 * Visits the Jetonomy setup wizard page and asserts it renders without
 * errors.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA02 — Run setup wizard', () => {

	test( 'setup wizard page renders', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-setup' );
		metrics.start();

		// Assert the wizard container renders.
		const wizard = page.locator(
			'.jetonomy-setup, .jetonomy-setup-wizard, #jetonomy-setup, [data-page="jetonomy-setup"]'
		);
		await expect( wizard.or( page.locator( 'h1:has-text("Setup"), h1:has-text("Jetonomy"), h2:has-text("Setup")' ) ) ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal — page should not be a blank white screen.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );
		expect( bodyText ).not.toContain( 'Call to undefined' );

		metrics.assertErrorCount( 0 );
	} );
} );
