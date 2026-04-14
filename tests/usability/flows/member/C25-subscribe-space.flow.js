// @ts-check
/**
 * C25 — Subscribe to a space (Follow/Following toggle).
 *
 * Visits an open space as a member, clicks the Follow button, and asserts
 * the button text toggles to "Following". Uses WP Interactivity API
 * `actions.followSpace`.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery, dbWrite, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists, assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C25 — Subscribe to a space', () => {

	const spaceId = users.spaceId( 'welcome' );
	const testUserId = users.id( 'alice' );

	test.beforeEach( () => {
		// Ensure alice is a member of the space.
		try {
			wp( [ 'jetonomy', 'member', 'join', `--space=${ spaceId }`, `--by=${ testUserId }` ] );
		} catch ( e ) { /* already a member */ }

		// Remove any existing subscription so we start clean.
		dbWrite( `DELETE FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'space' AND object_id = ${ spaceId }` );
	} );

	test.afterEach( () => {
		// Clean up subscription.
		dbWrite( `DELETE FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'space' AND object_id = ${ spaceId }` );
	} );

	test( 'clicking Follow on a space toggles to Following', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/s/welcome/' );
		metrics.start();

		// Find the Follow/Following button (Interactivity API driven).
		const followBtn = page.locator( 'button[data-wp-on--click="actions.followSpace"]' );
		await expect( followBtn ).toBeVisible( { timeout: 5000 } );

		// Button should say "Follow" initially (not subscribed).
		await expect( followBtn ).toHaveText( /Follow/i );

		// Click Follow.
		await followBtn.click();
		metrics.recordClick();

		// Button text should change to "Following".
		await expect( followBtn ).toHaveText( /Following/i, { timeout: 5000 } );

		// Button should have the jt-following class.
		await expect( followBtn ).toHaveClass( /jt-following/, { timeout: 5000 } );

		// Data flow: verify subscription row exists in DB.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'space' AND object_id = ${ spaceId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBeGreaterThan( 0 );

		const expectation = loadSpec( 'C25' );
		matchDelivery( expectation, {
			button_toggles_to_following: true,
			following_class_applied: true,
			subscription_row_created: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_subscribe: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
