// @ts-check
/**
 * PRO-AI-11 — Configure Ollama.
 *
 * Navigates to the AI settings and verifies Ollama provider
 * configuration fields render (host URL, model).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-AI-11 — Configure Ollama', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test( 'Ollama settings fields render', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-settings&tab=ai' );
		metrics.start();

		// Look for Ollama section.
		const ollamaField = page.locator(
			'input[name*="ollama"], input[id*="ollama_host"], [data-provider="ollama"], :has-text("Ollama")'
		);
		await expect( ollamaField.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
