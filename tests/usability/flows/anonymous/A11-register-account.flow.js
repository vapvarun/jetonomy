// @ts-check
/**
 * A11 — Register new account (anonymous).
 *
 * Visits the WordPress registration page and asserts the form renders.
 * Does NOT actually submit the form (that would be destructive).
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A11 — Register new account', () => {

	test( 'registration form renders at wp-login.php?action=register', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/wp-login.php?action=register` );

		// Either the registration form is present, or WP has disabled
		// registration (in which case a message is shown).
		const registerForm = page.locator( '#registerform, #setupform' );
		const disabledMessage = page.locator( '.message, #login_error' );

		const formVisible = await registerForm.isVisible().catch( () => false );
		const messageVisible = await disabledMessage.isVisible().catch( () => false );

		// At least one of these should be true — the page should not be blank.
		expect( formVisible || messageVisible ).toBeTruthy();

		if ( formVisible ) {
			// Username field is present.
			const usernameField = page.locator( '#user_login' );
			await expect( usernameField ).toBeVisible();

			// Email field is present.
			const emailField = page.locator( '#user_email' );
			await expect( emailField ).toBeVisible();
		}

		const expectation = loadSpec( 'A11' );
		matchDelivery( expectation, {
			page_renders: formVisible || messageVisible,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
