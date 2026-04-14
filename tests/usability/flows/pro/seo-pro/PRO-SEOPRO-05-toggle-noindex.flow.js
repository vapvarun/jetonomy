// @ts-check
/**
 * PRO-SEOPRO-05 — Toggle noindex.
 *
 * Sets a space to noindex via CLI, visits the space page, and
 * verifies the robots meta tag includes "noindex".
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-SEOPRO-05 — Toggle noindex', () => {

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
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'seo-pro', 'set', String( spaceId ), '--noindex=0' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'noindex meta tag appears when enabled', async ( { page } ) => {
		proJourney( [
			'seo-pro', 'set', String( spaceId ), '--noindex=1',
		] );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/` );

		const robots = page.locator( 'meta[name="robots"]' );
		const content = await robots.getAttribute( 'content' );
		expect( content ).toContain( 'noindex' );
	} );
} );
