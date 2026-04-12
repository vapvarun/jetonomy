// @ts-check
/**
 * A02 — Browse a public category (anonymous).
 *
 * Verifies that an unauthenticated visitor can navigate to a category page
 * and see space cards rendered inside it.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'A02 — Browse a public category', () => {

	test( 'anonymous visitor sees spaces inside the "community" category', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/category/community/` );

		// The page must not be a 404 — look for the community app shell.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Space cards should be rendered inside the category.
		const spaceCards = page.locator( 'a[href*="/community/s/"], .jt-space-card, .jt-row' );
		await expect( spaceCards.first() ).toBeVisible( { timeout: 5000 } );
		const count = await spaceCards.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		metrics.assertErrorCount( 0 );
	} );
} );
