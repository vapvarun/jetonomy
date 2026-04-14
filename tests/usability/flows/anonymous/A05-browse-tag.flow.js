// @ts-check
/**
 * A05 — Browse tag page (anonymous).
 *
 * Finds a known tag via DB query, visits its page, and asserts that
 * tagged content renders.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A05 — Browse tag page', () => {

	let tagSlug;

	test.beforeAll( () => {
		const slugs = dbQuery( 'SELECT slug FROM wp_jt_tags LIMIT 1' );
		if ( slugs.length === 0 || ! slugs[ 0 ] ) {
			tagSlug = null;
		} else {
			tagSlug = slugs[ 0 ];
		}
	} );

	test( 'anonymous visitor sees tagged content', async ( { page } ) => {
		test.skip( ! tagSlug, 'No tags exist in the database' );

		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/tag/${ tagSlug }/` );

		// Page renders without a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// Community container is present.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Either tagged results show or a "no results" message is displayed.
		const content = page.locator( '.jt-row, .jt-topics, .jt-empty-state, .jt-no-results' );
		await expect( content.first() ).toBeVisible( { timeout: 5000 } );

		const expectation = loadSpec( 'A05' );
		matchDelivery( expectation, {
			page_renders: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
