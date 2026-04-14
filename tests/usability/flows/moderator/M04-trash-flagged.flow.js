// @ts-check
/**
 * M04 — Trash flagged content.
 *
 * Seeds a flag, admin visits the mod queue, clicks the trash button on the
 * flagged item, and asserts the post status changes to trashed.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'M04 — Trash flagged content', () => {

	const spaceId = 1;
	let postId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M04 Trash Post ${ suffix }`,
			'--content=Post to be trashed for M04 test.',
		] );
		postId = post.data?.id || post.id;

		// Seed a flag.
		journey( [
			'flag', 'create',
			'--type=post',
			`--id=${ postId }`,
			'--user=3',
			'--reason=Content to trash for M04 test',
		] );
	} );

	test.afterEach( () => {
		if ( postId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'admin trashes a flagged post from the mod queue', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/mod/' );
		metrics.start();

		const flaggedItem = page.locator( '.jt-mod-item, .jt-flag-row' ).first();
		await expect( flaggedItem ).toBeVisible( { timeout: 5000 } );

		// Click the trash/delete button.
		const trashBtn = flaggedItem.locator(
			'button:has-text("Trash"), button:has-text("Delete"), button[data-action="trash"]'
		).first();
		await expect( trashBtn ).toBeVisible( { timeout: 3000 } );
		await trashBtn.click();
		metrics.recordClick();

		// Wait for the action to complete.
		await expect( flaggedItem ).not.toBeVisible( { timeout: 8000 } ).catch( async () => {
			await expect( flaggedItem.locator( '.resolved, .jt-resolved, .trashed' ) ).toBeVisible( { timeout: 3000 } );
		} );

		// DB: post status should be 'trashed'.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT status FROM wp_jt_posts WHERE id = ${ postId }`
			);
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( 'trashed' );

		const expectation = loadSpec( 'M04' );
		matchDelivery( expectation, {
			flagged_item_visible: true,
			post_status_changed_to_trashed: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
