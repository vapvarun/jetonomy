// @ts-check
/**
 * C34 — Update own bio/avatar.
 *
 * Visits the edit profile page, changes the bio text, saves, and
 * asserts the updated bio appears on the profile view.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C34 — Update own bio/avatar', () => {

	const testUserId = 3; // alice
	const testUserLogin = 'alice';
	let originalBio;

	test.beforeEach( () => {
		// Store the original bio so we can restore it.
		const bioRows = dbQuery( `SELECT bio FROM wp_jt_user_profiles WHERE user_id = ${ testUserId }` );
		originalBio = bioRows[ 0 ] || '';
	} );

	test.afterEach( () => {
		// Restore original bio.
		const escaped = ( originalBio || '' ).replace( /'/g, "\\'" );
		dbWrite( `UPDATE wp_jt_user_profiles SET bio = '${ escaped }' WHERE user_id = ${ testUserId }` );
	} );

	test( 'updating bio on edit profile page saves and displays on profile view', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const newBio = `C34 test bio updated at ${ Date.now() }`;

		// Navigate to edit profile.
		await autoLogin( page, testUserLogin, `/community/u/${ testUserLogin }/edit/` );
		metrics.start();

		// The edit profile form should be visible.
		const form = page.locator( '#jt-edit-profile' );
		await expect( form ).toBeVisible( { timeout: 5000 } );

		// Find the bio textarea and update it.
		const bioTextarea = form.locator( 'textarea[name="bio"]' );
		await expect( bioTextarea ).toBeVisible();
		await bioTextarea.clear();
		await bioTextarea.fill( newBio );
		metrics.recordClick();

		// Click the Save Profile button.
		const saveBtn = form.locator( 'button[type="submit"]' );
		await expect( saveBtn ).toBeVisible();
		await saveBtn.click();
		metrics.recordClick();

		// Wait for navigation to the profile view page or a success indication.
		await page.waitForURL( /\/community\/u\//, { timeout: 10000 } );

		// Verify the updated bio appears on the profile page.
		// Navigate to the profile view if we're not already there.
		const currentUrl = page.url();
		if ( currentUrl.includes( '/edit/' ) ) {
			// If still on edit page, navigate manually.
			await page.goto( `/community/u/${ testUserLogin }/` );
		}

		const profileBio = page.locator( '.jt-profile-bio' );
		await expect( profileBio ).toBeVisible( { timeout: 5000 } );
		await expect( profileBio ).toContainText( newBio );

		// Data flow: verify in DB.
		const dbBio = dbQuery( `SELECT bio FROM wp_jt_user_profiles WHERE user_id = ${ testUserId }` );
		expect( dbBio[ 0 ] ).toContain( 'C34 test bio' );

		const expectation = loadSpec( 'C34' );
		matchDelivery( expectation, {
			bio_form_visible: true,
			bio_saved_in_db: true,
			bio_visible_on_profile: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_save: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
