// @ts-check
/**
 * PRO-REPLYBYEMAIL-08 — Admin configure provider.
 *
 * Navigates to the Pro settings page and verifies the reply-by-email
 * provider configuration fields render.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REPLYBYEMAIL-08 — Admin configure provider', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'reply-by-email' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'reply-by-email' ] );
		}
	} );

	test( 'reply-by-email settings render in admin', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin(
			page, 1,
			'/wp-admin/admin.php?page=jetonomy-pro-settings&tab=reply-by-email'
		);
		metrics.start();

		// The settings wrapper should render.
		const wrapper = page.locator( '.wrap, .jetonomy-settings, .jt-settings' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Provider or mode selection field.
		const providerField = page.locator(
			'select[name*="provider"], select[name*="mode"], input[name*="imap_host"], [data-field="provider"]'
		);
		await expect( providerField.first() ).toBeVisible( { timeout: 5000 } );

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
