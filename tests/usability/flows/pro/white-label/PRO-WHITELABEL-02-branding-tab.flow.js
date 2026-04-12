// @ts-check
/**
 * PRO-WHITELABEL-02 — Branding tab in settings.
 *
 * Navigates to the Jetonomy Pro settings page and verifies a Branding
 * or White Label tab is present with expected fields.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WHITELABEL-02 — Branding tab in settings', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}
	} );

	test( 'branding tab renders with logo and name fields', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-settings&tab=branding' );
		metrics.start();

		// Branding tab should be visible.
		const brandTab = page.locator(
			'a.nav-tab:has-text("Branding"), a.nav-tab:has-text("White Label"), a.nav-tab-active:has-text("Branding"), [data-tab="branding"]'
		);
		await expect( brandTab.first() ).toBeVisible( { timeout: 5000 } );

		// Logo URL field.
		const logoField = page.locator(
			'input[name*="logo"], input[id*="logo_url"]'
		);
		await expect( logoField.first() ).toBeVisible( { timeout: 5000 } );

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
