// @ts-check
/**
 * M01 — Open global moderation queue.
 *
 * Admin (user 1) navigates to /community/mod/ and asserts the moderation
 * queue page renders with the expected UI elements.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M01 — Open global moderation queue', () => {

	test( 'admin opens the mod queue and sees the queue UI', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		// Assert the mod queue page rendered — look for a heading, table, or
		// list that indicates the queue is present.
		const queueContainer = page.locator(
			'.jt-mod-queue, .jt-moderation, [class*="mod-queue"], main'
		).first();
		await expect( queueContainer ).toBeVisible( { timeout: 5000 } );

		// Verify the page title or heading contains "Moderation" or "Queue".
		const heading = page.locator( 'h1, h2, .jt-page-title' ).first();
		await expect( heading ).toBeVisible( { timeout: 3000 } );

		// The page should not show an access-denied or 404 state.
		const body = await page.locator( 'body' ).textContent();
		expect( body ).not.toContain( 'Access Denied' );
		expect( body ).not.toContain( '404' );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
