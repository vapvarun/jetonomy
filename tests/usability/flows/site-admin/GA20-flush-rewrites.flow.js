// @ts-check
/**
 * GA20 — Flush rewrite rules
 *
 * Use WP-CLI to flush rewrite rules and assert success. Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'GA20 — Flush rewrite rules via CLI', () => {

	test( 'wp rewrite flush completes without error', () => {
		const output = wp( [ 'rewrite', 'flush' ] );
		expect( output ).toContain( 'Success' );
	} );

	test( 'community rewrite rules exist after flush', () => {
		const rules = wp( [ 'rewrite', 'list', '--format=csv' ] );
		// Jetonomy registers /community/* rewrite rules.
		expect( rules ).toContain( 'community' );
	} );
} );
