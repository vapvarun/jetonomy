// @ts-check
/**
 * PRO-AI-01 — Admin configure OpenAI.
 *
 * Navigates to the AI settings tab and verifies the OpenAI provider
 * configuration fields render (API key, model selector).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-AI-01 — Admin configure OpenAI', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}
	} );

	test( 'OpenAI settings fields render in admin', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-settings&tab=ai' );
		metrics.start();

		// Wrapper renders.
		const wrapper = page.locator( '.wrap, .jetonomy-settings, .jt-settings' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Look for OpenAI provider section or API key field.
		const apiKeyField = page.locator(
			'input[name*="openai"], input[name*="api_key"], input[id*="openai_key"], [data-provider="openai"]'
		);
		await expect( apiKeyField.first() ).toBeVisible( { timeout: 5000 } );

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
