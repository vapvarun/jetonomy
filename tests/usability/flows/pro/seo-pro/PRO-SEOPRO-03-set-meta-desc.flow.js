// @ts-check
/**
 * PRO-SEOPRO-03 — Set meta description.
 *
 * Sets a custom meta description for a space and verifies it persists.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-SEOPRO-03 — Set meta description', () => {

	let spaceId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'seo-pro' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'seo-pro' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'seo-pro', 'set', String( spaceId ), '--meta_description=' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'set and read back meta description', () => {
		const desc = 'A custom meta description for SEO testing purposes';
		const result = proJourney( [
			'seo-pro', 'set', String( spaceId ),
			`--meta_description=${ desc }`,
		] );
		expect( result.success ).toBe( true );

		const readback = proJourney( [ 'seo-pro', 'get', String( spaceId ) ] );
		expect( readback.data?.meta_description ).toBe( desc );
	} );
} );
