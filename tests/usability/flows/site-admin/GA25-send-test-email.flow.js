// @ts-check
/**
 * GA25 — Send test email
 *
 * Click the test email button on the email settings tab. Assert a success
 * notification appears or the email capture file records the outgoing mail.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { clear: clearMail, assertMailSent } = require( '../../helpers/email-capture' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA25 — Send test email from settings', () => {

	test.beforeEach( () => {
		clearMail();
	} );

	test( 'test email button sends mail and shows success', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=email' );
		metrics.start();

		// Click the test email button.
		const testEmailBtn = page.locator( 'button:has-text("Test Email"), button:has-text("Send Test"), a:has-text("Test Email")' ).first();
		await expect( testEmailBtn ).toBeVisible( { timeout: 5000 } );
		await testEmailBtn.click();
		metrics.recordClick();

		// Wait for either a success notice or the AJAX response.
		const successNotice = page.locator( '.notice-success, .jetonomy-notice-success, .updated, text=/sent|success/i' ).first();
		await expect( successNotice ).toBeVisible( { timeout: 10000 } );

		// Verify mail was captured.
		assertMailSent( /test/i );

		const expectation = loadSpec( 'GA25' );
		matchDelivery( expectation, {
			test_email_button_visible: true,
			success_notice_shown: true,
			email_captured: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
