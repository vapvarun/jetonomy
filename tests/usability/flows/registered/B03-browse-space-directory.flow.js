// @ts-check
/**
 * B03 — Browse space directory (registered).
 *
 * Auto-logs in as bob, visits the community home, and asserts space
 * cards/listings are visible to a logged-in user.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'B03 — Browse space directory', () => {

	test( 'logged-in bob sees space listings on community home', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'bob', '/community/' );
		metrics.start();

		// Community container renders.
		const container = page.locator( '.jt-two-col, .jt-app, .jt-container' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Space cards or links are visible.
		const spaceLinks = page.locator( 'a[href*="/community/s/"], .jt-space-card, .jt-row' );
		await expect( spaceLinks.first() ).toBeVisible( { timeout: 5000 } );
		const count = await spaceLinks.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		metrics.assertErrorCount( 0 );
	} );
} );
