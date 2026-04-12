// @ts-check
/**
 * PRO-EMAILDIGEST-01 — User sets frequency.
 *
 * Logs in as alice, visits notification preferences, selects a digest
 * frequency (daily/weekly/none), saves, and asserts the preference
 * is stored in user meta.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-EMAILDIGEST-01 — User sets frequency', () => {

	test.afterEach( () => {
		// Reset alice digest pref.
		try { wp( [ 'user', 'meta', 'delete', '3', 'jetonomy_digest_frequency' ] ); } catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'alice sets digest frequency to weekly', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/u/alice/edit/' );
		metrics.start();

		// Find the digest frequency selector.
		const freqSelect = page.locator( 'select[name="digest_frequency"], select[name="jetonomy_digest_frequency"]' );
		await expect( freqSelect ).toBeVisible( { timeout: 5000 } );
		await freqSelect.selectOption( 'weekly' );
		metrics.recordClick();

		// Save preferences.
		const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"]' );
		await saveBtn.click();
		metrics.recordClick();

		// Success feedback.
		const notice = page.locator( '.jt-notice-success, .jt-toast-success, .notice-success' );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		// Verify via WP-CLI.
		const freq = wp( [ 'user', 'meta', 'get', '3', 'jetonomy_digest_frequency' ] );
		expect( freq ).toBe( 'weekly' );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
