// @ts-check
/**
 * GA03 — Seed demo data.
 *
 * Uses the journey CLI to seed demo data, then asserts demo categories,
 * spaces, and posts exist in the database.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery } = require( '../../helpers/wp-cli' );

test.describe( 'GA03 — Seed demo data', () => {

	test.afterEach( () => {
		// Clean up demo data.
		try {
			journey( [ 'demo', 'cleanup' ] );
		} catch ( e ) { /* best effort */ }
	} );

	test( 'seed demo data via CLI and verify rows created', () => {
		const result = journey( [ 'demo', 'seed' ] );
		expect( result.success ).toBe( true );

		// Verify demo data tracking option exists.
		const demoOption = wp( [ 'option', 'get', 'jetonomy_demo_data', '--format=json' ], { json: true } );
		expect( demoOption ).toBeTruthy();

		// Verify demo categories exist.
		const catCount = dbQuery( 'SELECT COUNT(*) FROM wp_jt_categories' );
		expect( parseInt( catCount[ 0 ], 10 ) ).toBeGreaterThan( 0 );

		// Verify demo spaces exist.
		const spaceCount = dbQuery( 'SELECT COUNT(*) FROM wp_jt_spaces' );
		expect( parseInt( spaceCount[ 0 ], 10 ) ).toBeGreaterThan( 0 );

		// Verify demo posts exist.
		const postCount = dbQuery( 'SELECT COUNT(*) FROM wp_jt_posts' );
		expect( parseInt( postCount[ 0 ], 10 ) ).toBeGreaterThan( 0 );
	} );
} );
