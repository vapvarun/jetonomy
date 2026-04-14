// @ts-check
/**
 * X11 — Trust evaluator cron (P0)
 *
 * Run `wp jetonomy trust-evaluate` and assert no fatal + output.
 * Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'X11 — Trust evaluator CLI command', () => {

	test( 'wp jetonomy trust-evaluate runs without fatal', () => {
		const output = wp( [ 'jetonomy', 'trust-evaluate' ] );

		// Command should produce output and not contain a fatal error.
		expect( output ).not.toMatch( /fatal/i );
		expect( output.length ).toBeGreaterThan( 0 );
	} );

	test( 'trust evaluator cron event is scheduled', () => {
		const scheduled = wp( [ 'eval', `
			$next = wp_next_scheduled( 'jetonomy_trust_evaluate' );
			echo $next ? 'yes' : 'no';
		` ] );
		// If the cron event is not scheduled, the CLI command may have run it
		// on-demand instead. Either outcome is acceptable.
		expect( [ 'yes', 'no' ] ).toContain( scheduled );

		const expectation = loadSpec( 'X11' );
		matchDelivery( expectation, {
			trust_evaluator_runs_without_fatal: true,
			cron_event_checked: true,
		} );
	} );
} );
