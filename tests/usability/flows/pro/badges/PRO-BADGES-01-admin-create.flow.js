// @ts-check
/**
 * PRO-BADGES-01 — Admin create badge.
 *
 * Logs in as admin, navigates to the badges admin page, fills the
 * create form (name, description, icon, tier), submits, and asserts
 * the badge is stored in wp_jt_pro_badges.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-BADGES-01 — Admin create badge', () => {

	let badgeId;

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin creates a new badge via the admin page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const badgeName = `Test Badge ${ Date.now() }`;

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-badges' );
		metrics.start();

		// Click "Add Badge" or fill inline form.
		const addBtn = page.locator( 'button:has-text("Add Badge"), a:has-text("Add Badge")' );
		if ( await addBtn.isVisible() ) {
			await addBtn.click();
			metrics.recordClick();
		}

		// Fill name.
		const nameInput = page.locator( 'input[name="badge_name"], input[name="name"]' );
		await expect( nameInput ).toBeVisible( { timeout: 5000 } );
		await nameInput.fill( badgeName );

		// Fill description.
		const descInput = page.locator( 'textarea[name="badge_description"], textarea[name="description"]' );
		if ( await descInput.isVisible() ) {
			await descInput.fill( 'Awarded for testing.' );
		}

		// Select tier (bronze).
		const tierSelect = page.locator( 'select[name="badge_tier"], select[name="tier"]' );
		if ( await tierSelect.isVisible() ) {
			await tierSelect.selectOption( 'bronze' );
		}

		// Submit.
		const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"][value*="Save"]' );
		await saveBtn.click();
		metrics.recordClick();

		// Success notice.
		const notice = page.locator( '.notice-success, .updated' );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		// DB: badge exists.
		const ids = dbQuery( `SELECT id FROM wp_jt_pro_badges WHERE name LIKE '%${ badgeName.slice( 0, 10 ) }%' LIMIT 1` );
		expect( ids.length ).toBeGreaterThan( 0 );
		badgeId = parseInt( ids[ 0 ], 10 );

		assertDbRowExists( 'wp_jt_pro_badges', `id = ${ badgeId }` );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
