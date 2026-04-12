// @ts-check
/**
 * PRO-SEOPRO-02 — Frontend renders meta tags.
 *
 * Sets a custom meta title and description for a space, then visits
 * the space page and checks <head> for the correct meta tags.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-SEOPRO-02 — Frontend renders meta tags', () => {

	let spaceId;
	let spaceSlug;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'seo-pro' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'seo-pro' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
		const slugs = dbQuery( `SELECT slug FROM wp_jt_spaces WHERE id = ${ spaceId }` );
		spaceSlug = slugs[ 0 ];

		// Set SEO meta for the space.
		proJourney( [
			'seo-pro', 'set', String( spaceId ),
			'--meta_title=SEO Test Title',
			'--meta_description=SEO test description for verification',
		] );
	} );

	test.afterEach( () => {
		try {
			proJourney( [
				'seo-pro', 'set', String( spaceId ),
				'--meta_title=',
				'--meta_description=',
			] );
		} catch ( e ) { /* */ }
	} );

	test( 'space page contains custom meta tags in head', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/` );
		metrics.start();

		// Check <title> or og:title meta tag.
		const title = await page.locator( 'title' ).textContent();
		expect( title ).toContain( 'SEO Test Title' );

		// Check meta description.
		const metaDesc = page.locator( 'meta[name="description"]' );
		const descContent = await metaDesc.getAttribute( 'content' );
		expect( descContent ).toContain( 'SEO test description' );

		metrics.assertErrorCount( 0 );
	} );
} );
