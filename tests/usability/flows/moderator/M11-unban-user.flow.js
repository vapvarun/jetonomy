// @ts-check
/**
 * M11 — Unban a user.
 *
 * Pre-seeds a ban on bob via direct DB write, admin visits bob's profile,
 * clicks the unban button, and asserts the restriction row is removed.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M11 — Unban a user', () => {

	const targetUserId = 4; // bob

	test.beforeEach( () => {
		// Ensure bob IS banned before the test.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'ban'` );
		dbWrite(
			`INSERT INTO wp_jt_restrictions (user_id, type, reason, created_by, created_at) VALUES (${ targetUserId }, 'ban', 'M11 test ban', 1, NOW())`
		);
	} );

	test.afterEach( () => {
		// Clean up any remaining ban.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'ban'` );
	} );

	test( 'admin unbans bob from the community', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/u/bob/' );
		metrics.start();

		// Look for a mod actions area or more-menu with unban option.
		const modActions = page.locator(
			'.jt-mod-actions, .jt-admin-actions, .jt-user-actions'
		).first();

		const hasMod = await modActions.isVisible( { timeout: 3000 } ).catch( () => false );

		if ( hasMod ) {
			const unbanBtn = modActions.locator(
				'button:has-text("Unban"), button:has-text("Remove Ban"), button[data-action="unban"]'
			).first();
			await expect( unbanBtn ).toBeVisible( { timeout: 3000 } );
			await unbanBtn.click();
			metrics.recordClick();
		} else {
			const moreTrigger = page.locator( '.jt-more-trigger, .jt-profile-actions-trigger' ).first();
			await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
			await moreTrigger.click();
			metrics.recordClick();

			const unbanBtn = page.locator(
				'.jt-more-dropdown .jt-more-item', { hasText: /Unban|Remove Ban/i }
			).first();
			await expect( unbanBtn ).toBeVisible( { timeout: 3000 } );
			await unbanBtn.click();
			metrics.recordClick();
		}

		// Confirm if a dialog appears.
		const confirmBtn = page.locator(
			'button:has-text("Confirm"), button:has-text("Unban")'
		).first();
		if ( await confirmBtn.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
			await confirmBtn.click();
			metrics.recordClick();
		}

		// DB: restriction row should be gone.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'ban'`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
