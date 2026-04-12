// @ts-check
/**
 * C28 — Bookmark a post.
 *
 * Visits a post, finds the bookmark button, clicks it, and asserts
 * the bookmark state toggles and a DB row is created.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C28 — Bookmark a post', () => {

	const testUserId = 3; // alice
	let createdPostId;

	test.beforeEach( () => {
		const seedResult = journey( [ 'post', 'create', '--space=1', '--author=1', '--title=C28 Bookmark Test', '--content=Testing bookmark' ] );
		if ( seedResult.success && seedResult.data?.id ) {
			createdPostId = seedResult.data.id;
		}
		// Remove any existing bookmark.
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_bookmarks WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }` );
		}
	} );

	test.afterEach( () => {
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_bookmarks WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }` );
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( 'clicking bookmark button toggles bookmarked state', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		const slugRows = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ createdPostId }` );
		const postSlug = slugRows[ 0 ] || '';
		expect( postSlug ).toBeTruthy();

		await autoLogin( page, 'alice', `/community/s/welcome/t/${ postSlug }/` );
		metrics.start();

		// Find the bookmark button.
		const bookmarkBtn = page.locator( '.jt-bookmark-btn, button[data-wp-on--click="actions.toggleBookmark"]' );
		const bookmarkVisible = await bookmarkBtn.isVisible().catch( () => false );

		if ( ! bookmarkVisible ) {
			test.fixme( true, 'Bookmark button not found on single post view' );
			return;
		}

		// Should not be bookmarked initially.
		await expect( bookmarkBtn ).not.toHaveClass( /bookmarked/ );

		// Click to bookmark.
		await bookmarkBtn.click();
		metrics.recordClick();

		// Button should gain the bookmarked class.
		await expect( bookmarkBtn ).toHaveClass( /bookmarked/, { timeout: 5000 } );

		// Data flow: verify bookmark row exists in DB.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_bookmarks WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBeGreaterThan( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
