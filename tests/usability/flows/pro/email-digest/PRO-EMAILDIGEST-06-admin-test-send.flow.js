// @ts-check
/**
 * PRO-EMAILDIGEST-06 — Admin sends test digest.
 *
 * Logs in as admin, navigates to the email digest settings, clicks
 * "Send Test Digest", and asserts the test email is captured.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../../helpers/wp-cli' );
const { clear: clearMail, assertMailSent } = require( '../../../helpers/email-capture' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-EMAILDIGEST-06 — Admin sends test digest', () => {

	test.beforeEach( () => {
		clearMail();
	} );

	test.fixme( 'admin sends a test digest email', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-email-digest' );
		metrics.start();

		// Click "Send Test Digest" button.
		const testBtn = page.locator( 'button:has-text("Send Test"), button:has-text("Test Digest")' );
		await expect( testBtn ).toBeVisible( { timeout: 5000 } );
		await testBtn.click();
		metrics.recordClick();

		// Wait for AJAX success.
		const notice = page.locator( '.notice-success, .jt-toast-success' );
		await expect( notice ).toBeVisible( { timeout: 10000 } );

		// Email capture: test digest was sent.
		const mail = assertMailSent( /test.*digest|digest.*test/i );
		expect( mail ).toBeTruthy();

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
