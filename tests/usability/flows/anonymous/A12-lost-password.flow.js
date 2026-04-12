// @ts-check
/**
 * A12 — Lost password flow (anonymous).
 *
 * Visits the lost-password page and asserts the form renders with the
 * expected fields.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A12 — Lost password flow', () => {

	test( 'lost password form renders at wp-login.php?action=lostpassword', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/wp-login.php?action=lostpassword` );

		// The lost password form should be visible.
		const lostPasswordForm = page.locator( '#lostpasswordform' );
		await expect( lostPasswordForm ).toBeVisible( { timeout: 5000 } );

		// The username/email input field is present.
		const userInput = page.locator( '#user_login' );
		await expect( userInput ).toBeVisible();

		// Submit button is present.
		const submitButton = page.locator( '#wp-submit, input[type="submit"]' );
		await expect( submitButton.first() ).toBeVisible();

		const expectation = loadSpec( 'A12' );
		matchDelivery( expectation, {
			page_renders: true,
			form_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
