// @ts-check
/**
 * PRO-SEOPRO-06 — Exclude space from sitemap.
 *
 * Marks a space as excluded from sitemap via CLI, then verifies
 * the setting persists.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-SEOPRO-06 — Exclude space from sitemap', () => {

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
			proJourney( [ 'seo-pro', 'set', String( spaceId ), '--sitemap_exclude=0' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'exclude from sitemap flag persists', () => {
		const result = proJourney( [
			'seo-pro', 'set', String( spaceId ), '--sitemap_exclude=1',
		] );
		expect( result.success ).toBe( true );

		const readback = proJourney( [ 'seo-pro', 'get', String( spaceId ) ] );
		expect( String( readback.data?.sitemap_exclude ) ).toBe( '1' );
	} );
} );
