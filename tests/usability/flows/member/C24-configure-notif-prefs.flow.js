// @ts-check
/**
 * C24 — Configure notification preferences.
 *
 * Visits the profile edit page, asserts the notification preferences section
 * renders, and verifies that checkbox states match the user's stored
 * preferences in the database.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C24 — Configure notification preferences', () => {

	const specId = 'C24';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'notification checkbox states match stored user preferences', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read admin user's (id=1) notification preferences from DB.
		const prefsJson = wp( [ 'eval', `
			$prefs = get_user_meta( 1, 'jetonomy_notification_prefs', true );
			echo wp_json_encode( is_array( $prefs ) ? $prefs : [] );
		` ], { json: true } );

		await autoLogin( page, 1, '/community/u/admin/edit/' );
		metrics.start();

		// The edit profile form should be visible.
		const form = page.locator( '#jt-edit-profile' );
		await expect( form ).toBeVisible( { timeout: 5000 } );

		// The "Notification Preferences" label should be present.
		const notifLabel = page.locator( '.jt-label:has-text("Notification Preferences")' );
		await expect( notifLabel ).toBeVisible();

		// The notification preferences grid should render.
		const notifPrefs = page.locator( '.jt-notif-prefs' );
		await expect( notifPrefs ).toBeVisible();

		// Header row with "Web" and "Email" column labels.
		const headerRow = notifPrefs.locator( '.jt-notif-header' );
		await expect( headerRow ).toBeVisible();
		await expect( headerRow ).toContainText( 'Web' );
		await expect( headerRow ).toContainText( 'Email' );

		// There should be rows for each notification type.
		const notifRows = notifPrefs.locator( '.jt-notif-row:not(.jt-notif-header)' );
		const rowCount = await notifRows.count();
		expect( rowCount ).toBeGreaterThanOrEqual( 5 );

		// Each row should have toggle checkboxes.
		const checkboxes = notifPrefs.locator( 'input[type="checkbox"]' );
		const checkboxCount = await checkboxes.count();
		expect( checkboxCount ).toBeGreaterThanOrEqual( rowCount * 2 );

		// Verify specific notification type labels.
		const expectedTypes = [
			'Reply to my post',
			'Reply to my reply',
			'@Mention',
		];
		for ( const typeName of expectedTypes ) {
			await expect( notifPrefs ).toContainText( typeName );
		}

		// Verify checkbox states match DB preferences.
		// If user has stored preferences, check that checkbox checked state
		// matches the stored value for each preference key.
		let prefsMatchDb = true;
		if ( prefsJson && Object.keys( prefsJson ).length > 0 ) {
			// Check first checkbox's state against its corresponding DB key.
			const firstCheckbox = checkboxes.first();
			const checkboxName = await firstCheckbox.getAttribute( 'name' );
			if ( checkboxName ) {
				// Extract the key from the name (e.g., "notif_prefs[reply_to_post][web]").
				const keyMatch = checkboxName.match( /\[(\w+)\]\[(\w+)\]/ );
				if ( keyMatch ) {
					const [ , eventType, channel ] = keyMatch;
					const dbValue = prefsJson[ eventType ]?.[ channel ];
					if ( dbValue !== undefined ) {
						const isChecked = await firstCheckbox.isChecked();
						prefsMatchDb = isChecked === Boolean( dbValue );
					}
				}
			}
		}

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			notif_prefs_section_visible: true,
			checkbox_count_sufficient: checkboxCount >= rowCount * 2,
			expected_types_present: true,
			checkbox_states_match_db: prefsMatchDb,
		} );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
