// @ts-check
/**
 * A07 — View leaderboard (anonymous).
 *
 * Visits the leaderboard page and asserts a table or list of users renders.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A07 — View leaderboard', () => {

	test( 'anonymous visitor sees leaderboard content', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/leaderboard/` );

		// Page renders without a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// Community shell is present.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Leaderboard content — a table, list, or user rows.
		const leaderboard = page.locator( '.jt-leaderboard, table, .jt-user-row, .jt-lb-row, .jt-row' );
		await expect( leaderboard.first() ).toBeVisible( { timeout: 5000 } );

		const expectation = loadSpec( 'A07' );
		matchDelivery( expectation, {
			page_renders: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
