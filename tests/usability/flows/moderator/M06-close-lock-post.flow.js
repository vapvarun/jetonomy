// @ts-check
/**
 * M06 — Close/lock a post.
 *
 * Admin visits a post, finds the close/lock button in the more-menu, clicks
 * it, and asserts the post shows a "closed" or "locked" status indicator.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M06 — Close/lock a post', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=M06 Close Test ${ suffix }`,
			'--content=Post to be closed for M06 test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			// Re-open the post in case it was closed.
			dbWrite( `UPDATE wp_jt_posts SET is_closed = 0 WHERE id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'admin closes a post via the more-menu', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open the more menu on the post.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Close" or "Lock".
		const closeBtn = page.locator(
			'.jt-more-dropdown .jt-more-item', { hasText: /Close|Lock/i }
		).first();
		await expect( closeBtn ).toBeVisible( { timeout: 3000 } );
		await closeBtn.click();
		metrics.recordClick();

		// Assert the post now shows a closed/locked indicator.
		const closedIndicator = page.locator(
			'.jt-post-closed, .jt-closed-badge, [data-closed="true"], .jt-lock-icon, .jt-status-closed'
		).first();
		await expect( closedIndicator ).toBeVisible( { timeout: 8000 } );

		// DB: post is_closed should be 1.
		await expect.poll( () => {
			const rows = dbQuery( `SELECT is_closed FROM wp_jt_posts WHERE id = ${ postId }` );
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( '1' );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
