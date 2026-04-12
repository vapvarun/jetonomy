// @ts-check
/**
 * A06 — Search read-only (anonymous).
 *
 * Visits the search page with a query param. Asserts the page renders
 * search results or a "no results" state without errors.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'A06 — Search read-only', () => {

	test( 'anonymous visitor can execute a search', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/search/?q=test` );

		// Page renders without a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// Community shell is present.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Either search results or a "no results" empty state is visible.
		const results = page.locator( '.jt-row, .jt-search-results, .jt-topics, .jt-empty-state, .jt-no-results' );
		await expect( results.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
