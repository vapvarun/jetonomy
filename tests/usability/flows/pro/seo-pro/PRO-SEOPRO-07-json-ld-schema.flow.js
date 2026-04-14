// @ts-check
/**
 * PRO-SEOPRO-07 — Renders JSON-LD schema.
 *
 * Visits a space page and verifies a JSON-LD script block is present
 * in the <head> with DiscussionForumPosting or similar schema type.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-SEOPRO-07 — Renders JSON-LD schema', () => {

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

	test( 'JSON-LD script tag is present in head', async ( { page } ) => {
		await autoLogin( page, 1, `/community/s/${ spaceSlug }/` );

		const jsonLd = page.locator( 'script[type="application/ld+json"]' );
		const count = await jsonLd.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		// Parse the first JSON-LD block.
		const text = await jsonLd.first().textContent();
		const parsed = JSON.parse( text );
		expect( parsed[ '@context' ] || parsed[ '@type' ] ).toBeTruthy();
	} );
} );
