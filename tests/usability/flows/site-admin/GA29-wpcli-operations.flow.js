// @ts-check
/**
 * GA29 — WP-CLI operations
 *
 * Run `wp jetonomy status` and assert output contains version + row counts.
 * Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'GA29 — WP-CLI jetonomy status command', () => {

	test( 'wp jetonomy status returns version and row counts', () => {
		const output = wp( [ 'jetonomy', 'status' ] );

		// Output should contain a version string.
		expect( output ).toMatch( /version/i );

		// Output should contain table row counts or similar summary data.
		expect( output ).toMatch( /\d+/ );
	} );

	test( 'wp jetonomy qa runs without fatal', () => {
		const output = wp( [ 'jetonomy', 'qa' ] );

		// qa command should produce structured output.
		expect( output.length ).toBeGreaterThan( 0 );
		expect( output ).not.toMatch( /fatal/i );
	} );
} );
