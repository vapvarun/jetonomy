// @ts-check
/**
 * C27 — Unsubscribe from space/post.
 *
 * After subscribing to a space, clicks the "Following" button again to
 * unsubscribe and asserts the toggle reverts to "Follow".
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C27 — Unsubscribe from space/post', () => {

	const spaceId = 1; // Welcome space.
	const testUserId = 3; // alice

	test.beforeEach( () => {
		// Ensure alice is a member.
		try {
			wp( [ 'jetonomy', 'member', 'join', `--space=${ spaceId }`, `--by=${ testUserId }` ] );
		} catch ( e ) { /* already a member */ }

		// Create a subscription so alice is "Following".
		dbWrite( `DELETE FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'space' AND object_id = ${ spaceId }` );
		dbWrite( `INSERT INTO wp_jt_subscriptions (user_id, object_type, object_id, created_at) VALUES (${ testUserId }, 'space', ${ spaceId }, NOW())` );
	} );

	test.afterEach( () => {
		dbWrite( `DELETE FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'space' AND object_id = ${ spaceId }` );
	} );

	test( 'clicking Following toggles back to Follow (unsubscribe)', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/s/welcome/' );
		metrics.start();

		// Find the Follow/Following button.
		const followBtn = page.locator( 'button[data-wp-on--click="actions.followSpace"]' );
		await expect( followBtn ).toBeVisible( { timeout: 5000 } );

		// Button should say "Following" since we seeded the subscription.
		await expect( followBtn ).toHaveText( /Following/i );
		await expect( followBtn ).toHaveClass( /jt-following/ );

		// Click to unsubscribe.
		await followBtn.click();
		metrics.recordClick();

		// Button text should revert to "Follow".
		await expect( followBtn ).toHaveText( /^Follow$/i, { timeout: 5000 } );

		// Button should no longer have jt-following class.
		await expect( followBtn ).not.toHaveClass( /jt-following/, { timeout: 5000 } );

		// Data flow: subscription row should be removed.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'space' AND object_id = ${ spaceId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBe( 0 );

		const expectation = loadSpec( 'C27' );
		matchDelivery( expectation, {
			button_reverts_to_follow: true,
			following_class_removed: true,
			subscription_row_removed: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_unsubscribe: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
