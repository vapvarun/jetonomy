// @ts-check
/**
 * GA22 — Import from wpForo
 *
 * Visit the Jetonomy import page, assert the wpForo import option renders,
 * and verify the form action URL and nonce are present for security.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA22 — Import page shows wpForo import option', () => {

	const specId = 'GA22';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'wpForo import option has form action and nonce', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// The import page should show a wpForo option.
		const wpForoOption = page.locator( 'text=/wpforo/i' ).first();
		await expect( wpForoOption ).toBeVisible( { timeout: 5000 } );

		// Verify a form or button exists for initiating the import.
		const importForm = page.locator(
			'form[action*="import"], form[data-source="wpforo"], button[data-source="wpforo"], a[data-source="wpforo"], button:has-text("wpForo")'
		);
		const hasForm = await importForm.count() > 0;

		// Verify a nonce is present on the page.
		const nonceField = page.locator(
			'input[name="_wpnonce"], input[name*="nonce"], [data-nonce]'
		);
		const hasNonce = await nonceField.count() > 0;
		const pageContent = await page.content();
		const hasNonceInScript = /nonce|_wpnonce/.test( pageContent );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			wpforo_option_visible: true,
			import_form_or_button_present: hasForm,
			nonce_present: hasNonce || hasNonceInScript,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
