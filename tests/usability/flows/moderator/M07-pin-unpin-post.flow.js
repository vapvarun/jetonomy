// @ts-check
/**
 * M07 — Pin/unpin a post.
 *
 * Admin visits a post, finds the pin button in the more-menu, clicks it,
 * and asserts the post shows a pinned/sticky indicator. Then unpins it and
 * asserts the indicator is removed.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'M07 — Pin/unpin a post', () => {

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
			`--title=M07 Pin Test ${ suffix }`,
			'--content=Post to be pinned for M07 test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Ensure the post starts unpinned.
		dbWrite( `UPDATE wp_jt_posts SET is_pinned = 0 WHERE id = ${ postId }` );
	} );

	test.afterEach( () => {
		if ( postId ) {
			dbWrite( `UPDATE wp_jt_posts SET is_pinned = 0 WHERE id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'admin pins then unpins a post', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// --- PIN ---
		// Open the more menu.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Pin" or "Sticky".
		const pinBtn = page.locator(
			'.jt-more-dropdown .jt-more-item', { hasText: /Pin|Sticky/i }
		).first();
		await expect( pinBtn ).toBeVisible( { timeout: 3000 } );
		await pinBtn.click();
		metrics.recordClick();

		// Assert the pinned indicator appears.
		const pinnedIndicator = page.locator(
			'.jt-post-pinned, .jt-pinned-badge, [data-pinned="true"], .jt-pin-icon'
		).first();
		await expect( pinnedIndicator ).toBeVisible( { timeout: 8000 } );

		// DB: is_pinned should be 1.
		await expect.poll( () => {
			const rows = dbQuery( `SELECT is_pinned FROM wp_jt_posts WHERE id = ${ postId }` );
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( '1' );

		// --- UNPIN ---
		// Re-open the more menu.
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Unpin" or "Remove pin".
		const unpinBtn = page.locator(
			'.jt-more-dropdown .jt-more-item', { hasText: /Unpin|Remove pin/i }
		).first();
		await expect( unpinBtn ).toBeVisible( { timeout: 3000 } );
		await unpinBtn.click();
		metrics.recordClick();

		// Assert the pinned indicator is gone.
		await expect( pinnedIndicator ).not.toBeVisible( { timeout: 8000 } );

		// DB: is_pinned should be 0.
		await expect.poll( () => {
			const rows = dbQuery( `SELECT is_pinned FROM wp_jt_posts WHERE id = ${ postId }` );
			return rows[ 0 ];
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBe( '0' );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
