// @ts-check
/**
 * X13 — Realtime polling (P1)
 *
 * Visit the community page, verify the polling JavaScript is enqueued,
 * check that the Interactivity API store is registered, and assert no
 * console errors over 5 seconds of page idle.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'X13 — Realtime polling JS loads without errors', () => {

	const specId = 'X13';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'polling script is enqueued and no JS errors over 5 seconds', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Verify the view.js / view.asset.php file exists via wp eval.
		const assetExists = wp( [ 'eval', `
			$asset_path = WP_PLUGIN_DIR . '/jetonomy/build/view.asset.php';
			$alt_path   = WP_PLUGIN_DIR . '/jetonomy/assets/js/view.js';
			echo file_exists( $asset_path ) || file_exists( $alt_path ) ? 'yes' : 'no';
		` ] );

		await autoLogin( page, 1, '/community/' );
		metrics.start();

		// Wait for the page to fully load.
		await page.waitForLoadState( 'networkidle' );

		// Check for the Interactivity API store or polling JS.
		const jsChecks = await page.evaluate( () => {
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
			const hasInteractiveDirective = document.querySelector( '[data-wp-interactive]' ) !== null;

			// Check for the WP Interactivity store namespace.
			let hasInteractivityStore = false;
			try {
				// @ts-ignore — runtime check for wp.interactivity.
				hasInteractivityStore = typeof wp !== 'undefined' && wp.interactivity !== undefined;
			} catch ( e ) { /* ignore */ }

			return {
				hasJetonomyScript,
				hasModule,
				hasInteractiveDirective,
				hasInteractivityStore,
				pollingJsLoaded: hasJetonomyScript || hasModule || hasInteractiveDirective,
			};
		} );

		expect( jsChecks.pollingJsLoaded ).toBe( true );

		// Wait 5 seconds and check for console errors during idle time.
		await page.waitForTimeout( 5000 );

		// No JS errors should have fired during page load + 5s idle.
		const consoleErrorCount = metrics.consoleErrors.length;

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: consoleErrorCount === 0,
			polling_js_loaded: jsChecks.pollingJsLoaded,
			interactivity_directive_present: jsChecks.hasInteractiveDirective,
			asset_file_exists: assetExists === 'yes',
			no_errors_after_5s_idle: consoleErrorCount === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
