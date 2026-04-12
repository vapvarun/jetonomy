// @ts-check
/**
 * GA01 — Plugin activation.
 *
 * Pure CLI test. Deactivates and reactivates Jetonomy, asserts no fatal
 * errors and that core database tables exist.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );

test.describe( 'GA01 — Plugin activation', () => {

	test.afterEach( () => {
		// Ensure plugin is active after test.
		try {
			wp( [ 'plugin', 'activate', 'jetonomy' ] );
		} catch ( e ) { /* already active */ }
	} );

	test( 'deactivate and reactivate without fatal, tables exist', () => {
		// Deactivate.
		const deactivateOutput = wp( [ 'plugin', 'deactivate', 'jetonomy' ] );
		expect( deactivateOutput ).toContain( 'Success' );

		// Reactivate.
		const activateOutput = wp( [ 'plugin', 'activate', 'jetonomy' ] );
		expect( activateOutput ).toContain( 'Success' );

		// Verify core tables exist.
		const tables = [
			'wp_jt_categories', 'wp_jt_spaces', 'wp_jt_posts',
			'wp_jt_replies', 'wp_jt_votes', 'wp_jt_user_profiles',
		];
		for ( const table of tables ) {
			const rows = dbQuery( `SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '${ table }'` );
			expect( parseInt( rows[ 0 ], 10 ) ).toBeGreaterThanOrEqual( 1 );
		}

		// Verify plugin is active.
		const status = wp( [ 'plugin', 'get', 'jetonomy', '--field=status' ] );
		expect( status ).toBe( 'active' );
	} );
} );
