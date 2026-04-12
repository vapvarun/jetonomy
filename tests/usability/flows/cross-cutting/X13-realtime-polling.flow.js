// @ts-check
/**
 * X13 — Realtime polling (P1)
 *
 * Visit the community page and check that the polling JavaScript is loaded.
 * Assert no console errors from the polling module.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'X13 — Realtime polling JS loads without errors', () => {

	test( 'polling script is present and no JS errors on community page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/' );
		metrics.start();

		// Wait for the page to fully load.
		await page.waitForLoadState( 'networkidle' );

		// Check for the Interactivity API store or polling JS.
		const hasPollingJs = await page.evaluate( () => {
			// Check for Interactivity API store (jetonomy uses viewScriptModule).
			const scripts = Array.from( document.querySelectorAll( 'script[src]' ) );
			const hasJetonomyScript = scripts.some( ( s ) =>
				s.src.includes( 'jetonomy' ) || s.src.includes( 'view' )
			);
			// Also check for module scripts.
			const modules = Array.from( document.querySelectorAll( 'script[type="module"]' ) );
			const hasModule = modules.some( ( s ) =>
				s.src && ( s.src.includes( 'jetonomy' ) || s.src.includes( 'view' ) )
			);
			return hasJetonomyScript || hasModule || document.querySelector( '[data-wp-interactive]' ) !== null;
		} );

		expect( hasPollingJs ).toBe( true );

		// No JS errors should have fired during page load.
		metrics.assertErrorCount( 0 );
	} );
} );
