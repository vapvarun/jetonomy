// @ts-check
/**
 * PRO-SEOPRO-04 — Set OG image.
 *
 * Sets a custom Open Graph image URL for a space and verifies it
 * renders in the <head>.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-SEOPRO-04 — Set OG image', () => {

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
			proJourney( [ 'seo-pro', 'set', String( spaceId ), '--og_image=' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'og:image tag renders on space page', async ( { page } ) => {
		const ogUrl = 'https://example.com/og-test-image.png';
		proJourney( [
			'seo-pro', 'set', String( spaceId ),
			`--og_image=${ ogUrl }`,
		] );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/` );

		const ogImage = page.locator( 'meta[property="og:image"]' );
		const content = await ogImage.getAttribute( 'content' );
		expect( content ).toBe( ogUrl );
	} );
} );
