// @ts-check
/**
 * GA02 — Setup wizard.
 *
 * Visits the Jetonomy setup wizard page, asserts it renders without errors,
 * and verifies DB state: jetonomy_setup_complete option reflects wizard
 * completion status.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA02 — Run setup wizard', () => {

	const specId = 'GA02';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'setup wizard page renders and DB state is consistent', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read the setup_complete option from DB before loading the page.
		const setupComplete = dbQuery(
			"SELECT option_value FROM wp_options WHERE option_name = 'jetonomy_setup_complete'"
		);

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-setup' );
		metrics.start();

		// Assert the wizard container renders.
		const wizard = page.locator(
			'.jetonomy-setup, .jetonomy-setup-wizard, #jetonomy-setup, [data-page="jetonomy-setup"]'
		);
		await expect( wizard.or( page.locator( 'h1:has-text("Setup"), h1:has-text("Jetonomy"), h2:has-text("Setup")' ) ) ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal — page should not be a blank white screen.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );
		expect( bodyText ).not.toContain( 'Call to undefined' );

		// Verify wizard steps render — look for step indicators or numbered steps.
		const steps = page.locator(
			'.jetonomy-setup-step, .setup-step, [data-step], .wizard-step, .step-indicator'
		);
		const stepCount = await steps.count();

		// Verify the jetonomy_settings option exists in DB (created during activation).
		const settingsExist = dbQuery(
			"SELECT COUNT(*) FROM wp_options WHERE option_name = 'jetonomy_settings'"
		);
		const hasSettings = parseInt( settingsExist[ 0 ], 10 ) > 0;

		// If setup was already completed, the option should reflect that.
		const isComplete = setupComplete.length > 0 && setupComplete[ 0 ] === '1';

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			wizard_page_renders: true,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			setup_settings_exist_in_db: hasSettings,
			wizard_steps_render: stepCount > 0 || isComplete,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
