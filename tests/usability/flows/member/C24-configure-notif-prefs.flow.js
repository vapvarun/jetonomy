// @ts-check
/**
 * C24 — Configure notification preferences.
 *
 * Visits the profile edit page and asserts that the notification
 * preferences section renders with checkboxes for each notification type.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C24 — Configure notification preferences', () => {

	test( 'notification preference checkboxes render on edit profile page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

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
		// We expect at least 5 notification types (reply_to_post, reply_to_reply,
		// mention, vote_on_post, accepted_answer, new_post_in_sub, badge_earned).
		expect( rowCount ).toBeGreaterThanOrEqual( 5 );

		// Each row should have toggle checkboxes.
		const checkboxes = notifPrefs.locator( 'input[type="checkbox"]' );
		const checkboxCount = await checkboxes.count();
		// At least 2 per row (web + email).
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

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
