// @ts-check
/**
 * M12 — Silence user (post-restricted).
 *
 * Admin visits bob's profile, applies a silence restriction (user cannot
 * create new posts/replies but can still browse), and asserts the
 * restriction row is created in wp_jt_restrictions with type='silence'.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'M12 — Silence user (post-restricted)', () => {

	const targetUserId = 4; // bob

	test.beforeEach( () => {
		// Ensure bob is not already silenced.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'silence'` );
	} );

	test.afterEach( () => {
		// Clean up the silence.
		dbWrite( `DELETE FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'silence'` );
	} );

	test.fixme( 'admin silences bob from creating content', async ( { page } ) => {
		// FIXME: user-profile frontend view does not yet expose ban/silence mod actions.
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/u/bob/' );
		metrics.start();

		// Look for mod actions area.
		const modActions = page.locator(
			'.jt-mod-actions, .jt-admin-actions, .jt-user-actions'
		).first();

		const hasMod = await modActions.isVisible( { timeout: 3000 } ).catch( () => false );

		if ( hasMod ) {
			const silenceBtn = modActions.locator(
				'button:has-text("Silence"), button:has-text("Mute"), button[data-action="silence"]'
			).first();
			await expect( silenceBtn ).toBeVisible( { timeout: 3000 } );
			await silenceBtn.click();
			metrics.recordClick();
		} else {
			const moreTrigger = page.locator( '.jt-more-trigger, .jt-profile-actions-trigger' ).first();
			await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
			await moreTrigger.click();
			metrics.recordClick();

			const silenceBtn = page.locator(
				'.jt-more-dropdown .jt-more-item', { hasText: /Silence|Mute/i }
			).first();
			await expect( silenceBtn ).toBeVisible( { timeout: 3000 } );
			await silenceBtn.click();
			metrics.recordClick();
		}

		// Confirm if a dialog appears.
		const confirmBtn = page.locator(
			'button:has-text("Confirm"), button:has-text("Silence"), button.jt-btn-danger'
		).first();
		if ( await confirmBtn.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
			await confirmBtn.click();
			metrics.recordClick();
		}

		// DB: silence restriction row should exist.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_restrictions WHERE user_id = ${ targetUserId } AND type = 'silence'`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBeGreaterThan( 0 );

		const expectation = loadSpec( 'M12' );
		matchDelivery( expectation, {
			silence_restriction_created: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
