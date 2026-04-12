// @ts-check
/**
 * B01 — First login redirect (registered).
 *
 * Auto-logs in as bob, visits wp-admin, and asserts that the community
 * nav link is accessible in the header/admin bar.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'B01 — First login -> community redirect', () => {

	test( 'logged-in user can access community nav link from wp-admin', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Log in as bob and land on wp-admin.
		await autoLogin( page, 'bob', '/wp-admin/' );
		metrics.start();

		// The admin bar or admin menu should be visible (confirming login worked).
		const adminBar = page.locator( '#wpadminbar, #adminmenu' );
		await expect( adminBar.first() ).toBeVisible( { timeout: 8000 } );

		// A community link should be accessible — either in the admin bar,
		// the admin menu sidebar, or via a top-bar community nav.
		const communityLink = page.locator(
			'a[href*="/community/"], .jt-community-nav, a:has-text("Community")'
		);
		await expect( communityLink.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
