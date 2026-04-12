// @ts-check
/**
 * GA23 — Import from Asgaros (P2)
 *
 * Visit the Jetonomy import page, assert the Asgaros import option renders,
 * and verify the form action URL and nonce are present for security.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA23 — Import page shows Asgaros import option', () => {

	const specId = 'GA23';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'Asgaros import option has form action and nonce', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// The import page should show an Asgaros option.
		const asgarosOption = page.locator( 'text=/asgaros/i' ).first();
		await expect( asgarosOption ).toBeVisible( { timeout: 5000 } );

		// Verify a form or button exists for initiating the import.
		const importForm = page.locator(
			'form[action*="import"], form[data-source="asgaros"], button[data-source="asgaros"], a[data-source="asgaros"], button:has-text("Asgaros")'
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
			asgaros_option_visible: true,
			import_form_or_button_present: hasForm,
			nonce_present: hasNonce || hasNonceInScript,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
