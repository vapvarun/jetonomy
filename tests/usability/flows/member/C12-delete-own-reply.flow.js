// @ts-check
/**
 * C12 — Delete own reply.
 *
 * Seeds a post + reply as alice, visits the post, opens the reply
 * more-menu, clicks Delete, confirms, and asserts the reply is removed.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C12 — Delete own reply', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const authorId = 3; // alice
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=C12 Post ${ suffix }`,
			'--content=Post for reply deletion test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ authorId }`,
			`--content=Reply to be deleted by alice ${ suffix }`,
		] );
		replyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		// Safety cleanup.
		if ( replyId ) {
			try { journey( [ 'reply', 'delete', String( replyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice deletes her own reply', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Handle confirm dialog.
		page.on( 'dialog', async ( dialog ) => {
			await dialog.accept();
		} );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Find the reply and open its more-menu.
		const replyCard = page.locator( '.jt-reply' ).first();
		await expect( replyCard ).toBeVisible( { timeout: 5000 } );

		const moreTrigger = replyCard.locator( '.jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible();
		await moreTrigger.click();
		metrics.recordClick();

		// Click Delete.
		const deleteBtn = replyCard.locator( '.jt-more-item--danger', { hasText: /Delete/i } );
		await expect( deleteBtn ).toBeVisible( { timeout: 3000 } );
		await deleteBtn.click();
		metrics.recordClick();

		// Wait for the reply to be removed from the DOM.
		await expect( replyCard ).not.toBeVisible( { timeout: 10000 } );

		// DB: reply should be gone.
		assertDbRowAbsent( 'wp_jt_replies', `id = ${ replyId } AND status = 'publish'` );

		// Mark cleaned.
		replyId = null;

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
