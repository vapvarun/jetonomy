// @ts-check
/**
 * PRO-AI-06 — Daily budget cap enforcement.
 *
 * Sets a daily API budget cap and verifies the setting persists.
 * (Actual enforcement requires provider calls, so we test config only.)
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-06 — Daily budget cap enforcement', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test.afterEach( () => {
		try {
			proJourney( [ 'ai', 'set-budget', '--daily_cap=0' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'set daily budget cap and read back', () => {
		const result = proJourney( [
			'ai', 'set-budget', '--daily_cap=100',
		] );
		expect( result.success ).toBe( true );

		const readback = proJourney( [ 'ai', 'get-config' ] );
		expect( readback.success ).toBe( true );
		expect( String( readback.data?.daily_cap || readback.data?.budget?.daily_cap ) ).toBe( '100' );
	} );
} );
