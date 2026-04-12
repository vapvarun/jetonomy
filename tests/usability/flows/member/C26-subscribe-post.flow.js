// @ts-check
/**
 * C26 — Subscribe to a post (Follow/Following toggle).
 *
 * Visits a single post, clicks the Follow button on the post, and
 * asserts the toggle changes to "Following".
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C26 — Subscribe to a post', () => {

	const testUserId = 3; // alice
	let createdPostId;

	test.beforeEach( () => {
		// Seed a post for testing.
		const seedResult = journey( [ 'post', 'create', '--space=1', '--author=1', '--title=C26 Subscribe Post Test', '--content=Testing post subscription' ] );
		if ( seedResult.success && seedResult.data?.id ) {
			createdPostId = seedResult.data.id;
		}
		// Remove any existing subscription.
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'post' AND object_id = ${ createdPostId }` );
		}
	} );

	test.afterEach( () => {
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'post' AND object_id = ${ createdPostId }` );
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( 'clicking Follow on a post toggles to Following', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get the post slug.
		const slugRows = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ createdPostId }` );
		const postSlug = slugRows[ 0 ] || '';
		expect( postSlug ).toBeTruthy();

		// Login as alice (not the author, so Follow button is visible).
		await autoLogin( page, 'alice', `/community/s/welcome/t/${ postSlug }/` );
		metrics.start();

		// Find the Follow/Following button on the post.
		const followBtn = page.locator( 'button[data-wp-on--click="actions.followPost"]' );
		await expect( followBtn ).toBeVisible( { timeout: 5000 } );

		// Button should say "Follow" initially.
		await expect( followBtn ).toHaveText( /Follow$/i );

		// Click Follow.
		await followBtn.click();
		metrics.recordClick();

		// Button text should change to "Following".
		await expect( followBtn ).toHaveText( /Following/i, { timeout: 5000 } );

		// Data flow: verify subscription row exists.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_subscriptions WHERE user_id = ${ testUserId } AND object_type = 'post' AND object_id = ${ createdPostId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBeGreaterThan( 0 );

		const expectation = loadSpec( 'C26' );
		matchDelivery( expectation, {
			button_toggles_to_following: true,
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
