// @ts-check
/**
 * PRO-BADGES-08 — Admin delete badge.
 *
 * Admin deletes a badge and asserts the badge row and all user_badge
 * rows are removed (cascade cleanup).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-BADGES-08 — Admin delete badge', () => {

	let badgeId;

	test.beforeEach( () => {
		const badge = proJourney( [ 'badges', 'create', '--name=Delete Me', '--tier=bronze' ] );
		badgeId = badge.data?.id;

		// Award to alice so we can test cascade.
		if ( badgeId ) {
			proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );
		}
	} );

	test.afterEach( () => {
		// Safety cleanup in case delete failed.
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin deletes a badge and awards are cascade-removed', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-badges' );
		metrics.start();

		// Find the badge row and click delete.
		const deleteBtn = page.locator( `button[data-badge-id="${ badgeId }"][data-action="delete"], a[href*="delete"][href*="${ badgeId }"]` );
		await expect( deleteBtn ).toBeVisible( { timeout: 5000 } );
		await deleteBtn.click();
		metrics.recordClick();

		// Handle confirmation dialog if present.
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		// Wait for the row to disappear.
		await expect( deleteBtn ).not.toBeVisible( { timeout: 5000 } );

		// DB: badge and awards removed.
		assertDbRowAbsent( 'wp_jt_pro_badges', `id = ${ badgeId }` );
		assertDbRowAbsent( 'wp_jt_pro_user_badges', `badge_id = ${ badgeId }` );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
