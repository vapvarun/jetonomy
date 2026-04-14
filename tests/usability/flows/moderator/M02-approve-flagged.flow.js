// @ts-check
/**
 * M02 — Approve flagged content.
 *
 * Seeds a flag via journey CLI, admin visits the mod queue, clicks the
 * approve button on the flagged item, and asserts the flag is resolved.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'M02 — Approve flagged content', () => {

	const spaceId = 1;
	let postId;
	let flagId;

	test.beforeEach( () => {
		const suffix = Date.now();
		// Seed a post by bob (user 4).
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M02 Flagged Post ${ suffix }`,
			'--content=Post to be flagged for M02 test.',
		] );
		postId = post.data?.id || post.id;

		// Seed a flag on this post by alice (user 3).
		const flag = journey( [
			'flag', 'create',
			'--type=post',
			`--id=${ postId }`,
			'--user=3',
			'--reason=Test flag for M02 approve flow',
		] );
		flagId = flag.data?.id || flag.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin approves a flagged post from the mod queue', async ( { page } ) => {
		// FIXME: Flag fixture requires seeded users 3 (alice) and 4 (bob); not guaranteed on this site.
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		// Wait for the queue to load and find the flagged item.
		const flaggedItem = page.locator( `.jt-mod-item[data-flag-id="${ flagId }"], .jt-mod-item, .jt-flag-row` ).first();
		await expect( flaggedItem ).toBeVisible( { timeout: 5000 } );

		// Click the approve/keep button.
		const approveBtn = flaggedItem.locator(
			'button:has-text("Approve"), button:has-text("Keep"), button:has-text("Dismiss"), button[data-action="approve"]'
		).first();
		await expect( approveBtn ).toBeVisible( { timeout: 3000 } );
		await approveBtn.click();
		metrics.recordClick();

		// Wait for the item to be removed from the queue or show resolved state.
		await expect( flaggedItem ).not.toBeVisible( { timeout: 8000 } ).catch( async () => {
			// Alternative: the item shows a "resolved" badge instead of disappearing.
			await expect( flaggedItem.locator( '.resolved, .jt-resolved' ) ).toBeVisible( { timeout: 3000 } );
		} );

		// DB: flag should be resolved.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT status FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ postId } AND user_id = 3`
			);
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( 'resolved' );

		const expectation = loadSpec( 'M02' );
		matchDelivery( expectation, {
			flagged_item_visible_in_queue: true,
			flag_resolved_after_approve: true,
			db_flag_status_resolved: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
