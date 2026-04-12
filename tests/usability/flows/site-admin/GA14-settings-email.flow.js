// @ts-check
/**
 * GA14 — Settings: Email tab
 *
 * Visit the Jetonomy settings page with the email tab selected.
 * Assert that from_name, from_email, and notification defaults fields render.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA14 — Settings email tab renders from_name / from_email / defaults', () => {

	test( 'email configuration fields are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=email' );
		metrics.start();

		// from_name field.
		const fromName = page.locator( 'input[name*="from_name"], input[id*="from_name"]' ).first();
		await expect( fromName ).toBeVisible( { timeout: 5000 } );

		// from_email field.
		const fromEmail = page.locator( 'input[name*="from_email"], input[id*="from_email"]' ).first();
		await expect( fromEmail ).toBeVisible( { timeout: 5000 } );

		// Notification defaults section — look for a heading or fieldset.
		const notificationDefaults = page.locator( 'text=/notification.*default/i' ).first();
		await expect( notificationDefaults ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
