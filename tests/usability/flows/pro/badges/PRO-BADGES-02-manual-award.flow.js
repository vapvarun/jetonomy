// @ts-check
/**
 * PRO-BADGES-02 — Manual award to user.
 *
 * Admin manually awards a badge to alice via the admin UI or CLI,
 * and asserts the award row exists in wp_jt_pro_user_badges.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-BADGES-02 — Manual award to user', () => {

	let badgeId;

	test.beforeEach( () => {
		// Create a badge via CLI.
		const badge = proJourney( [ 'badges', 'create', '--name=Manual Award Test', '--tier=silver' ] );
		badgeId = badge.data?.id;
	} );

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin awards a badge to alice', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/wp-admin/admin.php?page=jetonomy-pro-badges&action=award&badge_id=${ badgeId }` );
		metrics.start();

		// Search for alice.
		const userSearch = page.locator( 'input[name="user_search"], input[name="username"]' );
		await expect( userSearch ).toBeVisible( { timeout: 5000 } );
		await userSearch.fill( 'alice' );

		// Select alice from suggestions.
		const suggestion = page.locator( '.jt-user-suggestion:has-text("alice")' );
		if ( await suggestion.isVisible( { timeout: 3000 } ).catch( () => false ) ) {
			await suggestion.click();
			metrics.recordClick();
		}

		// Click Award.
		const awardBtn = page.locator( 'button:has-text("Award"), input[type="submit"][value*="Award"]' );
		await awardBtn.click();
		metrics.recordClick();

		// Success.
		const notice = page.locator( '.notice-success, .updated' );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		// DB: user_badge row.
		assertDbRowExists( 'wp_jt_pro_user_badges', `badge_id = ${ badgeId } AND user_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
