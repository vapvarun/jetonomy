// @ts-check
/**
 * C20 — Mark post as read (passive).
 *
 * P2 — Visits a post and checks whether a row is created in the
 * wp_jt_read_status table, confirming passive read tracking works.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C20 — Mark post as read (passive)', () => {

	const testUserId = 3; // alice
	let createdPostId;

	test.afterEach( () => {
		// Clean up read status rows seeded by this test.
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_read_status WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }` );
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( 'visiting a post creates a read_status row for the logged-in user', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Seed a fresh post.
		const seedResult = journey( [ 'post', 'create', '--space=1', '--author=1', '--title=C20 Read Tracking Test', '--content=Passive read test body' ] );
		if ( seedResult.success && seedResult.data?.id ) {
			createdPostId = seedResult.data.id;
		}
		expect( createdPostId ).toBeGreaterThan( 0 );

		// Get the post slug.
		const slugRows = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ createdPostId }` );
		const postSlug = slugRows[ 0 ] || '';
		expect( postSlug ).toBeTruthy();

		// Ensure no read_status row exists yet.
		const beforeRows = dbQuery( `SELECT COUNT(*) FROM wp_jt_read_status WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }` );
		expect( parseInt( beforeRows[ 0 ], 10 ) ).toBe( 0 );

		// Visit the post as alice.
		await autoLogin( page, 'alice', `/community/s/welcome/t/${ postSlug }/` );
		metrics.start();

		// Confirm the post page loaded.
		await expect( page.locator( 'h1' ) ).toContainText( 'C20 Read Tracking Test', { timeout: 5000 } );

		// Wait for the read tracking to propagate (background fetch or inline).
		await expect.poll( () => {
			const rows = dbQuery( `SELECT COUNT(*) FROM wp_jt_read_status WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }` );
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBeGreaterThan( 0 );

		// Data flow assertion.
		assertDbRowExists( 'wp_jt_read_status', `user_id = ${ testUserId } AND post_id = ${ createdPostId }` );

		const expectation = loadSpec( 'C20' );
		matchDelivery( expectation, {
			read_status_row_created: true,
			post_page_renders: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
