// @ts-check
/**
 * PRO-AI-04 — Test provider with probe.
 *
 * Sends a test probe to the configured AI provider to verify
 * connectivity. Skips if no provider is configured.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-04 — Test provider with probe', () => {

	let providerConfigured = false;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}

		try {
			const config = proJourney( [ 'ai', 'provider-status' ] );
			providerConfigured = config.success && config.data?.configured === true;
		} catch ( e ) {
			providerConfigured = false;
		}
	} );

	test( 'probe returns success for configured provider', () => {
		if ( ! providerConfigured ) {
			test.skip( true, 'No AI provider configured — skipping probe test' );
			return;
		}

		const probe = proJourney( [ 'ai', 'test-provider' ] );
		expect( probe.success ).toBe( true );
		expect( probe.data?.reachable ).toBe( true );
	} );
} );
