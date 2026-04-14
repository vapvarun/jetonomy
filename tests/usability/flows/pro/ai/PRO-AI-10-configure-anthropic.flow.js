// @ts-check
/**
 * PRO-AI-10 — Configure Anthropic.
 *
 * Navigates to the AI settings and verifies Anthropic provider
 * configuration fields render (API key, model).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-AI-10 — Configure Anthropic', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test( 'Anthropic settings fields render', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-settings&tab=ai' );
		metrics.start();

		// Look for Anthropic section.
		const anthropicField = page.locator(
			'input[name*="anthropic"], input[id*="anthropic_key"], [data-provider="anthropic"], :has-text("Anthropic")'
		);
		await expect( anthropicField.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
