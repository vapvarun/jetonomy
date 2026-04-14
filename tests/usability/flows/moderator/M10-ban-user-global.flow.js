// @ts-check
/**
 * M10 — Ban a user (global).
 *
 * Admin navigates to the community user profile or admin users page,
 * applies a global ban on a test user, and asserts a restriction row
 * is created in the database.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'M10 — Ban a user (global)', () => {

	const targetUserId = 4; // bob

	test.beforeEach( () => {
		// Ensure bob is not already banned.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'ban'` );
	} );

	test.afterEach( () => {
		// Clean up the ban.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'ban'` );
	} );

	test.fixme( 'admin bans bob globally from the community', async ( { page } ) => {
		// FIXME: user-profile frontend view does not yet expose ban/silence mod actions.
		const metrics = new EaseMetrics( page );

		// Visit bob's profile page where the ban button should be available to admin.
		await autoLogin( page, 1, '/community/u/bob/' );
		metrics.start();

		// Look for a mod actions area or a more-menu with ban option.
		const modActions = page.locator(
			'.jt-mod-actions, .jt-admin-actions, .jt-user-actions'
		).first();

		const hasMod = await modActions.isVisible( { timeout: 3000 } ).catch( () => false );

		if ( hasMod ) {
			// Direct ban button on the profile.
			const banBtn = modActions.locator(
				'button:has-text("Ban"), button[data-action="ban"]'
			).first();
			await expect( banBtn ).toBeVisible( { timeout: 3000 } );
			await banBtn.click();
			metrics.recordClick();
		} else {
			// Fallback: open more-menu on profile.
			const moreTrigger = page.locator( '.jt-more-trigger, .jt-profile-actions-trigger' ).first();
			await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
			await moreTrigger.click();
			metrics.recordClick();

			const banBtn = page.locator(
				'.jt-more-dropdown .jt-more-item', { hasText: /Ban/i }
			).first();
			await expect( banBtn ).toBeVisible( { timeout: 3000 } );
			await banBtn.click();
			metrics.recordClick();
		}

		// A confirmation dialog may appear.
		const confirmBtn = page.locator(
			'button:has-text("Confirm"), button:has-text("Ban"), button.jt-btn-danger'
		).first();
		if ( await confirmBtn.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
			await confirmBtn.click();
			metrics.recordClick();
		}

		// DB: restriction row should exist.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'ban'`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBeGreaterThan( 0 );

		const expectation = loadSpec( 'M10' );
		matchDelivery( expectation, {
			ban_restriction_created: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
