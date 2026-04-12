// @ts-check
/**
 * PRO-AI-03 — Enable spam_detection feature.
 *
 * Toggles the AI spam detection feature on/off via CLI and verifies
 * the setting persists.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-03 — Enable spam_detection feature', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test( 'enable and disable spam detection feature', () => {
		// Enable spam detection.
		const enable = proJourney( [
			'ai', 'feature', 'spam_detection', '--enable',
		] );
		expect( enable.success ).toBe( true );

		// Verify it is enabled.
		let features = proJourney( [ 'ai', 'features' ] );
		const spamEnabled = features.data?.spam_detection;
		expect( spamEnabled ).toBeTruthy();

		// Disable it.
		const disable = proJourney( [
			'ai', 'feature', 'spam_detection', '--disable',
		] );
		expect( disable.success ).toBe( true );

		// Verify it is disabled.
		features = proJourney( [ 'ai', 'features' ] );
		expect( features.data?.spam_detection ).toBeFalsy();
	} );
} );
