// @ts-check
/**
 * GA17 — Settings: Anti-Spam tab
 *
 * Visit the Jetonomy settings page with the anti-spam tab selected.
 * Assert that CAPTCHA, rate limit, and honeypot fields render.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA17 — Settings anti-spam tab renders CAPTCHA + rate limit + honeypot', () => {

	test( 'anti-spam configuration fields are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=anti-spam' );
		metrics.start();

		// CAPTCHA section.
		const captchaSection = page.locator( 'text=/captcha/i' ).first();
		await expect( captchaSection ).toBeVisible( { timeout: 5000 } );

		// Rate limit section.
		const rateLimitSection = page.locator( 'text=/rate.*limit/i' ).first();
		await expect( rateLimitSection ).toBeVisible( { timeout: 5000 } );

		// Honeypot section.
		const honeypotSection = page.locator( 'text=/honeypot/i' ).first();
		await expect( honeypotSection ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
