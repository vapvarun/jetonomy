// @ts-check
/**
 * PRO-SEOPRO-01 — Admin set per-space meta title.
 *
 * Uses proJourney CLI to set a custom meta title for a space,
 * then verifies it persists in the DB.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-SEOPRO-01 — Admin set per-space meta title', () => {

	let spaceId;
	let originalTitle;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'seo-pro' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'seo-pro' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		// Save original value for restore.
		try {
			const current = proJourney( [ 'seo-pro', 'get', String( spaceId ) ] );
			originalTitle = current.data?.meta_title || '';
		} catch ( e ) {
			originalTitle = '';
		}
	} );

	test.afterEach( () => {
		// Restore original meta title.
		try {
			proJourney( [
				'seo-pro', 'set', String( spaceId ),
				`--meta_title=${ originalTitle }`,
			] );
		} catch ( e ) { /* */ }
	} );

	test( 'set a custom meta title via CLI', () => {
		const customTitle = 'Custom SEO Title for Testing';
		const result = proJourney( [
			'seo-pro', 'set', String( spaceId ),
			`--meta_title=${ customTitle }`,
		] );

		expect( result.success ).toBe( true );

		// Read back and verify.
		const readback = proJourney( [ 'seo-pro', 'get', String( spaceId ) ] );
		expect( readback.success ).toBe( true );
		expect( readback.data?.meta_title ).toBe( customTitle );
	} );
} );
