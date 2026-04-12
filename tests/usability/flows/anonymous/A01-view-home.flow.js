// @ts-check
/**
 * A01 — View community home (anonymous).
 *
 * Verifies that an unauthenticated visitor can load the community home page,
 * see the two-column layout, and find space listings without JS errors.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A01 — View community home', () => {

	test( 'anonymous visitor sees community layout and spaces', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/` );

		// Main community container renders.
		const container = page.locator( '.jt-two-col, .jt-app, .jt-container' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// At least one space card / link is present on the home page.
		const spaceLinks = page.locator( 'a[href*="/community/s/"]' );
		await expect( spaceLinks.first() ).toBeVisible( { timeout: 5000 } );
		const count = await spaceLinks.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		const expectation = loadSpec( 'A01' );
		matchDelivery( expectation, {
			community_container_visible: true,
			space_listings_present: count >= 1,
			renders_at_390px: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		// No JS errors.
		metrics.assertErrorCount( 0 );
	} );

	test( 'renders at 390px mobile viewport', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/` );

		const container = page.locator( '.jt-two-col, .jt-app, .jt-container' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
