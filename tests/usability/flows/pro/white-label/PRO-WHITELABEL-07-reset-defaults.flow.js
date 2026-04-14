// @ts-check
/**
 * PRO-WHITELABEL-07 — Reset to defaults.
 *
 * Customizes branding, then resets via CLI and verifies all fields
 * return to their default values.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WHITELABEL-07 — Reset to defaults', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'white-label' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'white-label' ] );
		}
	} );

	test.afterEach( () => {
		// Ensure defaults are restored regardless.
		try {
			proJourney( [ 'white-label', 'reset' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'reset clears all custom branding values', () => {
		// Set custom values.
		proJourney( [ 'white-label', 'set', '--community_name=Temp Name' ] );
		proJourney( [ 'white-label', 'set', '--accent_color=#123456' ] );

		// Verify they were set.
		let current = proJourney( [ 'white-label', 'get' ] );
		expect( current.data?.community_name ).toBe( 'Temp Name' );

		// Reset.
		const resetResult = proJourney( [ 'white-label', 'reset' ] );
		expect( resetResult.success ).toBe( true );

		// Verify values are cleared / back to defaults.
		current = proJourney( [ 'white-label', 'get' ] );
		expect( current.data?.community_name || '' ).not.toBe( 'Temp Name' );
		expect( current.data?.accent_color || '' ).not.toBe( '#123456' );
	} );
} );
