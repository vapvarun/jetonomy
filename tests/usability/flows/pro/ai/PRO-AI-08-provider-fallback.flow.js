// @ts-check
/**
 * PRO-AI-08 — Provider fallback on error.
 *
 * Tests that when the primary provider fails, the fallback provider
 * is tried. Requires at least two providers configured.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-08 — Provider fallback on error', () => {

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

	test.fixme( 'fallback provider is used when primary fails', () => {
		if ( ! providerConfigured ) {
			test.skip( true, 'No AI provider configured — skipping fallback test' );
			return;
		}

		// Set fallback chain with an invalid primary.
		proJourney( [
			'ai', 'set-fallback-chain',
			'--providers=invalid_provider,ollama',
		] );

		// Trigger a request — should fall back to ollama.
		const result = proJourney( [ 'ai', 'test-provider' ] );
		expect( result.success ).toBe( true );
		expect( result.data?.provider_used || result.data?.provider ).not.toBe( 'invalid_provider' );
	} );
} );
