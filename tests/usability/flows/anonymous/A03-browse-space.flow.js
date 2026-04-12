// @ts-check
/**
 * A03 — Browse a public space (anonymous).
 *
 * Verifies that an unauthenticated visitor can view a space, see its post
 * listing (.jt-topics / .jt-row), and see sort tabs.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'A03 — Browse a public space', () => {

	test( 'anonymous visitor sees post listing and sort tabs in /community/s/welcome/', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/s/welcome/` );

		// Post listing container renders.
		const topics = page.locator( '.jt-topics' );
		await expect( topics ).toBeVisible( { timeout: 8000 } );

		// At least one post row is present.
		const rows = page.locator( '.jt-topics .jt-row' );
		await expect( rows.first() ).toBeVisible( { timeout: 5000 } );
		const count = await rows.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		// Sort tabs are visible (e.g. Latest, Popular, etc.).
		const sortTabs = page.locator( '.jt-sort-tabs, .jt-sub-nav, [data-wp-on--click*="sort"]' );
		await expect( sortTabs.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
