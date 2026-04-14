// @ts-check
/**
 * GA21 — Import from bbPress
 *
 * Visit the Jetonomy import page, assert the bbPress import option renders,
 * and verify the form action URL and nonce are present for security.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA21 — Import page shows bbPress import option', () => {

	const specId = 'GA21';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'bbPress import option has form action and nonce', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// The import page should show a bbPress option (card, button, or heading).
		const bbPressOption = page.locator( 'text=/bbpress/i' ).first();
		await expect( bbPressOption ).toBeVisible( { timeout: 5000 } );

		// Verify a form or button exists for initiating the import.
		const importForm = page.locator(
			'form[action*="import"], form[data-source="bbpress"], button[data-source="bbpress"], a[data-source="bbpress"], button:has-text("bbPress")'
		);
		const hasForm = await importForm.count() > 0;

		// Verify a nonce field or AJAX nonce is present on the page.
		const nonceField = page.locator(
			'input[name="_wpnonce"], input[name*="nonce"], [data-nonce]'
		);
		const hasNonce = await nonceField.count() > 0;

		// Also check for a nonce in the page source (could be in a script tag).
		const pageContent = await page.content();
		const hasNonceInScript = /nonce|_wpnonce/.test( pageContent );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			bbpress_option_visible: true,
			import_form_or_button_present: hasForm,
			nonce_present: hasNonce || hasNonceInScript,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
