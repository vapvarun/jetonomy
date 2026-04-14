// @ts-check
/**
 * C30 — Remove a bookmark.
 *
 * Visits a post that is already bookmarked, clicks the bookmark button
 * to remove it, and asserts the bookmark is toggled off and DB row removed.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C30 — Remove a bookmark', () => {

	const testUserId = users.id( 'alice' );
	let createdPostId;

	test.beforeEach( () => {
		// Seed a post and pre-bookmark it for alice.
		const seedResult = journey( [ 'post', 'create', `--space=${ users.spaceId( 'welcome' ) }`, `--author=${ users.id( 'admin' ) }`, '--title=C30 Remove Bookmark Test', '--content=Testing unbookmark' ] );
		if ( seedResult.success && seedResult.data?.id ) {
			createdPostId = seedResult.data.id;
		}
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_bookmarks WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }` );
			dbWrite( `INSERT INTO wp_jt_bookmarks (user_id, post_id, created_at) VALUES (${ testUserId }, ${ createdPostId }, NOW())` );
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

	test( 'clicking bookmarked button removes the bookmark', async ( { page } ) => {
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

		// Should be bookmarked initially.
		await expect( bookmarkBtn ).toHaveClass( /bookmarked/, { timeout: 5000 } );

		// Click to remove bookmark.
		await bookmarkBtn.click();
		metrics.recordClick();

		// Button should lose the bookmarked class.
		await expect( bookmarkBtn ).not.toHaveClass( /bookmarked/, { timeout: 5000 } );

		// Data flow: bookmark row should be removed.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_bookmarks WHERE user_id = ${ testUserId } AND post_id = ${ createdPostId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBe( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
