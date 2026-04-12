// @ts-check
/**
 * X12 — Activity backfill (P2)
 *
 * Run `wp jetonomy backfill-activity` and assert success output.
 * Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'X12 — Activity backfill CLI command', () => {

	test( 'wp jetonomy backfill-activity runs without fatal', () => {
		const output = wp( [ 'jetonomy', 'backfill-activity' ] );

		// Command should complete and not contain a fatal error.
		expect( output ).not.toMatch( /fatal/i );
		expect( output.length ).toBeGreaterThan( 0 );

		const expectation = loadSpec( 'X12' );
		matchDelivery( expectation, {
			backfill_runs_without_fatal: true,
			produces_output: output.length > 0,
		} );
	} );
} );
