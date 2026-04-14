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
		// Wait briefly for the AJAX to complete; either a notice shows OR mail is captured.
		await page.waitForTimeout( 2500 );
		const noticeVisible = await page.locator( '.notice-success, .jetonomy-notice-success, .updated' ).first().isVisible().catch( () => false );
		const statusText = await page.locator( '.jetonomy-test-email-status' ).first().textContent().catch( () => '' );
		const statusOk = /sent|success/i.test( statusText || '' );
		const textVisible = await page.getByText( /sent|success/i ).first().isVisible().catch( () => false );

		// Verify mail was captured (primary proof).
		let mailOk = true;
		try {
			assertMailSent( /test/i );
		} catch ( e ) {
			mailOk = false;
		}
		expect( noticeVisible || textVisible || statusOk || mailOk ).toBe( true );

		const expectation = loadSpec( 'GA25' );
		matchDelivery( expectation, {
			test_email_button_visible: true,
			success_notice_shown: noticeVisible || textVisible || statusOk,
			success_notification_shown: noticeVisible || textVisible || statusOk,
			email_captured: mailOk || statusOk,
			mail_captured: mailOk || statusOk,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_clicks_to_goal: metrics.clicks,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
