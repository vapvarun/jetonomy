// @ts-check
/**
 * GA14 — Settings: Email tab
 *
 * Visit the Jetonomy settings page with the email tab selected.
 * Assert that from_name and from_email field values match the DB.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA14 — Settings email tab renders from_name / from_email / defaults', () => {

	const specId = 'GA14';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'email field values match database settings', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read email settings from DB.
		const settingsJson = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo wp_json_encode( [
				'from_name'  => $s['from_name'] ?? '',
				'from_email' => $s['from_email'] ?? '',
			] );
		` ], { json: true } );

		const dbFromName = settingsJson?.from_name || '';
		const dbFromEmail = settingsJson?.from_email || '';

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=email' );
		metrics.start();

		// from_name field.
		const fromName = page.locator( 'input[name*="from_name"], input[id*="from_name"]' ).first();
		await expect( fromName ).toBeVisible( { timeout: 5000 } );
		const renderedFromName = await fromName.inputValue();

		// from_email field.
		const fromEmail = page.locator( 'input[name*="from_email"], input[id*="from_email"]' ).first();
		await expect( fromEmail ).toBeVisible( { timeout: 5000 } );
		const renderedFromEmail = await fromEmail.inputValue();

		// If DB has a from_name set, the input must match.
		const fromNameMatchesDb = dbFromName === '' || renderedFromName === dbFromName;
		const fromEmailMatchesDb = dbFromEmail === '' || renderedFromEmail === dbFromEmail;

		// Notification defaults section — look for a heading or fieldset.
		const notificationDefaults = page.locator( 'text=/notification.*default/i' ).first();
		const notifDefaultsVisible = await notificationDefaults.isVisible().catch( () => false );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			from_name_field_visible: true,
			from_email_field_visible: true,
			notification_defaults_visible: notifDefaultsVisible,
			from_name_matches_db: fromNameMatchesDb,
			from_email_matches_db: fromEmailMatchesDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
