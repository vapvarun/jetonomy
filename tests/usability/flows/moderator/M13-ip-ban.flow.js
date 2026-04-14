// @ts-check
/**
 * M13 — IP ban (P2).
 *
 * IP banning UI is not yet implemented in the frontend. This test is
 * marked as fixme. When implemented, it should allow an admin to ban a
 * specific IP address and assert the restriction row is created.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M13 — IP ban', () => {

	const testIp = '192.0.2.99'; // RFC 5737 TEST-NET — safe to use.

	test.beforeEach( () => {
		// Clean up any existing IP ban for the test IP.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE type = 'ip_ban' AND ip_address = '${ testIp }'` );
	} );

	test.afterEach( () => {
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE type = 'ip_ban' AND ip_address = '${ testIp }'` );
	} );

	test.fixme( 'admin bans an IP address from the community', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Navigate to the mod settings or IP ban management page.
		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		// Look for an IP ban tab or section.
		const ipBanTab = page.locator(
			'a:has-text("IP Bans"), button:has-text("IP Bans"), [data-tab="ip-bans"]'
		).first();
		await expect( ipBanTab ).toBeVisible( { timeout: 5000 } );
		await ipBanTab.click();
		metrics.recordClick();

		// Enter the IP address.
		const ipInput = page.locator(
			'input[name="ip_address"], input[placeholder*="IP"], .jt-ip-ban-input'
		).first();
		await expect( ipInput ).toBeVisible( { timeout: 5000 } );
		await ipInput.fill( testIp );
		metrics.recordClick();

		// Submit the ban.
		const banBtn = page.locator(
			'button:has-text("Ban IP"), button:has-text("Add"), button[type="submit"]'
		).first();
		await expect( banBtn ).toBeVisible( { timeout: 3000 } );
		await banBtn.click();
		metrics.recordClick();

		// DB: IP ban restriction row should exist.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_restrictions WHERE type = 'ip_ban' AND ip_address = '${ testIp }'`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBeGreaterThan( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
