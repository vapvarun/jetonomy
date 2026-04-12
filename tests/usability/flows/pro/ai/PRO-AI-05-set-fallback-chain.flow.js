// @ts-check
/**
 * PRO-AI-05 — Set default provider + fallback.
 *
 * Configures a provider fallback chain via CLI and verifies
 * the order persists in settings.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-05 — Set default provider + fallback', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test( 'set fallback chain and read back', () => {
		const result = proJourney( [
			'ai', 'set-fallback-chain',
			'--providers=openai,ollama',
		] );
		expect( result.success ).toBe( true );

		const readback = proJourney( [ 'ai', 'get-config' ] );
		expect( readback.success ).toBe( true );

		const chain = readback.data?.fallback_chain || readback.data?.providers;
		expect( chain ).toBeTruthy();
		expect( chain[ 0 ] ).toBe( 'openai' );
		expect( chain[ 1 ] ).toBe( 'ollama' );
	} );
} );
