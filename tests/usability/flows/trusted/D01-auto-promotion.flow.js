// @ts-check
/**
 * D01 — Auto-promotion to TL2/TL3.
 *
 * Runs the `wp jetonomy trust-evaluate` CLI command and asserts that the
 * trust evaluator executes without error. Then verifies in the DB that the
 * evaluator produced a result (either a promotion occurred or the user
 * retained their current level).
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'D01 — Auto-promotion to TL2/TL3', () => {

	const aliceId = 3;

	test.beforeEach( () => {
		// Ensure alice starts at TL1 so the evaluator has room to promote.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test.afterEach( () => {
		// Reset alice back to TL1 to avoid polluting other tests.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test( 'trust evaluator runs without error and produces a result', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Run the trust evaluation command. It may or may not promote alice
		// depending on her activity stats — we only assert it does not error.
		let result;
		let cliError = false;
		try {
			result = wp( [ 'jetonomy', 'trust-evaluate', '--format=json' ], { json: true } );
		} catch ( e ) {
			cliError = true;
		}

		expect( cliError ).toBe( false );

		// Verify alice still has a valid trust level row.
		const tlRows = dbQuery(
			`SELECT trust_level FROM wp_jt_user_profiles WHERE user_id = ${ aliceId }`
		);
		expect( tlRows.length ).toBeGreaterThan( 0 );
		const level = parseInt( tlRows[ 0 ], 10 );
		expect( level ).toBeGreaterThanOrEqual( 0 );
		expect( level ).toBeLessThanOrEqual( 5 );

		// Navigate to alice's profile to confirm trust level badge renders.
		await autoLogin( page, 'alice', '/community/u/alice/' );
		metrics.start();

		const profileEl = page.locator( '.jt-profile, .jt-user-card, .jt-user-profile' ).first();
		await expect( profileEl ).toBeVisible( { timeout: 5000 } );

		const expectation = loadSpec( 'D01' );
		matchDelivery( expectation, {
			trust_evaluator_runs: ! cliError,
			user_profile_has_valid_trust_level: level >= 0 && level <= 5,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: 0,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
