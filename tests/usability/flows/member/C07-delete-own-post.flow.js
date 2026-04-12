// @ts-check
/**
 * C07 — Delete own post.
 *
 * Seeds a post as alice, visits it, opens the more-menu, clicks Delete,
 * confirms the dialog, and asserts the post is removed (redirect to space
 * page + DB row gone).
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C07 — Delete own post', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const authorId = 3; // alice
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ authorId }`,
			`--title=C07 Delete Me ${ suffix }`,
			'--content=This post will be deleted in the test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		// Safety cleanup in case the delete failed.
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice deletes her own post', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Handle the browser confirm dialog that deletePost triggers.
		page.on( 'dialog', async ( dialog ) => {
			await dialog.accept();
		} );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open more-menu.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click Delete.
		const deleteBtn = page.locator( '.jt-more-dropdown .jt-more-item--danger', { hasText: /Delete/i } );
		await expect( deleteBtn ).toBeVisible( { timeout: 3000 } );
		await deleteBtn.click();
		metrics.recordClick();

		// Should redirect to the space page after deletion.
		await page.waitForURL( /\/community\/s\/welcome\//, { timeout: 10000 } );

		// DB: post should be gone (or soft-deleted).
		assertDbRowAbsent( 'wp_jt_posts', `id = ${ postId } AND status = 'publish'` );

		// Mark as cleaned so afterEach doesn't double-delete.
		postId = null;

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
