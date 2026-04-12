// @ts-check
/**
 * C29 — View own bookmarks.
 *
 * Visits the bookmarks tab on the user profile and asserts the page
 * renders with bookmarked posts listed.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C29 — View own bookmarks', () => {

	const testUserId = 3; // alice
	let createdPostId;

	test.beforeEach( () => {
		// Seed a post and bookmark it for alice.
		const seedResult = journey( [ 'post', 'create', '--space=1', '--author=1', '--title=C29 Bookmarked Post', '--content=This post is bookmarked' ] );
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

	test( 'bookmarks tab renders with saved posts', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Profile URL uses path-based tabs: /community/u/alice/bookmarks/
		await autoLogin( page, 'alice', '/community/u/alice/bookmarks/' );
		metrics.start();

		// Profile page should load.
		const profileName = page.locator( '.jt-profile-name' );
		await expect( profileName ).toBeVisible( { timeout: 5000 } );

		// The bookmarks tab should be active.
		const bookmarksTab = page.locator( '.jt-profile-tab.active' );
		await expect( bookmarksTab ).toBeVisible();
		await expect( bookmarksTab ).toContainText( 'Bookmarks' );

		// There should be at least one bookmarked post in the list.
		const rows = page.locator( '.jt-topics .jt-row' );
		const rowCount = await rows.count();
		expect( rowCount ).toBeGreaterThan( 0 );

		// The seeded post title should appear.
		await expect( page.locator( '.jt-topics' ) ).toContainText( 'C29 Bookmarked Post' );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
