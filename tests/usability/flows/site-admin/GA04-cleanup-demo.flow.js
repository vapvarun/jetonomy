// @ts-check
/**
 * GA04 — Cleanup demo data.
 *
 * Seeds demo data first, then runs cleanup via CLI, and asserts demo
 * data is removed.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA04 — Cleanup demo data', () => {

	test( 'seed then cleanup demo data via CLI', () => {
		// Seed first.
		const seedResult = journey( [ 'demo-seed' ] );
		expect( seedResult.success ).toBe( true );

		// Capture a demo space title for verification.
		const demoOption = wp( [ 'option', 'get', 'jetonomy_demo_data', '--format=json' ], { json: true } );
		expect( demoOption ).toBeTruthy();

		// Run cleanup.
		const cleanupResult = journey( [ 'demo-cleanup' ] );
		expect( cleanupResult.success ).toBe( true );

		// Verify demo tracking option is cleared.
		const afterOption = wp( [ 'eval', `
			$d = get_option( 'jetonomy_demo_data', [] );
			echo empty( $d ) ? 'empty' : 'exists';
		` ] );
		expect( afterOption ).toBe( 'empty' );

		const expectation = loadSpec( 'GA04' );
		matchDelivery( expectation, {
			seed_succeeds: seedResult.success,
			cleanup_succeeds: cleanupResult.success,
			demo_data_cleared: afterOption === 'empty',
		} );
	} );
} );
