// @ts-check
/**
 * D06 — Split reply into new topic (P2).
 *
 * If the split-reply-to-topic button exists in the UI for TL3+ users,
 * tests that alice can split bob's reply into a new standalone post.
 * If the UI is not yet implemented, the test is marked as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'D06 — Split reply into new topic', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const aliceId = 3;
	const bobId = 4;
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		// Set alice to TL3.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 3 WHERE user_id = ${ aliceId }` );

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ aliceId }`,
			`--title=D06 Split Test ${ suffix }`,
			'--content=Post for D06 split reply test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Create a reply by bob to be split.
		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ bobId }`,
			`--content=Bob reply to split into topic ${ suffix }`,
		] );
		replyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		if ( replyId ) {
			try { journey( [ 'reply', 'delete', String( replyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
		// Clean up any newly-created post from the split.
		dbWrite( `DELETE FROM wp_jt_posts WHERE title LIKE 'D06 Split%' AND author_id = ${ bobId }` );
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test.fixme( 'alice at TL3 splits bob\'s reply into a new topic', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Locate bob's reply.
		const replyEl = page.locator( `.jt-reply[data-reply-id="${ replyId }"], .jt-reply` ).first();
		await expect( replyEl ).toBeVisible( { timeout: 5000 } );

		// Open the more menu on the reply.
		const moreTrigger = replyEl.locator( '.jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 3000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Split to topic" button.
		const splitBtn = page.locator( '.jt-more-dropdown .jt-more-item', { hasText: /Split/i } ).first();
		await expect( splitBtn ).toBeVisible( { timeout: 3000 } );
		await splitBtn.click();
		metrics.recordClick();

		// A confirmation dialog or modal may appear.
		const confirmBtn = page.locator( 'button:has-text("Confirm"), button:has-text("Split"), button:has-text("OK")' ).first();
		if ( await confirmBtn.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
			await confirmBtn.click();
			metrics.recordClick();
		}

		// Verify the reply was removed from the current thread or a success
		// message appeared.
		const successIndicator = page.locator( '.jt-toast-success, .jt-notice-success' );
		await expect( successIndicator ).toBeVisible( { timeout: 8000 } );

		// Verify a new post was created in the DB from the split.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_posts WHERE space_id = ${ spaceId } AND author_id = ${ bobId } AND id != ${ postId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000 } ).toBeGreaterThan( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
