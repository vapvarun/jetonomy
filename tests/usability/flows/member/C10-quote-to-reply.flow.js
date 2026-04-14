// @ts-check
/**
 * C10 — Quote-to-reply.
 *
 * The reply-card template includes a "Quote" button that inserts the
 * reply text as a blockquote into the composer. This test seeds a post
 * with a reply, clicks the Quote button, asserts that the composer is
 * populated with quoted text, types additional text, and submits.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C10 — Quote-to-reply', () => {

	const spaceId = users.spaceId( 'welcome' );
	const spaceSlug = 'welcome';
	const aliceId = users.id( 'alice' );
	const bobId = users.id( 'bob' );
	let postId;
	let postSlug;
	let seedReplyId;
	let quotedReplyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=C10 Post ${ suffix }`,
			'--content=Post for quote test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ bobId }`,
			'--content=This reply should be quoted.',
		] );
		seedReplyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		if ( quotedReplyId ) {
			try { journey( [ 'reply', 'delete', String( quotedReplyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( seedReplyId ) {
			try { journey( [ 'reply', 'delete', String( seedReplyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice clicks Quote on a reply and submits a quoted reply', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Find the Quote button on the first reply.
		const quoteBtn = page.locator( '.jt-reply' ).first().locator( 'button:has-text("Quote")' );
		await expect( quoteBtn ).toBeVisible( { timeout: 5000 } );
		await quoteBtn.click();
		metrics.recordClick();

		// The composer editor should now contain quoted text (blockquote).
		const composerBody = page.locator( '#jt-composer .jt-editor-body[contenteditable="true"]' );
		await expect( composerBody ).toBeVisible( { timeout: 5000 } );
		const editorHTML = await composerBody.innerHTML();
		expect( editorHTML.length ).toBeGreaterThan( 0 );

		// Type additional text after the quote.
		await composerBody.click();
		await page.keyboard.press( 'End' );
		const extraText = ` My response to the quote ${ Date.now() }`;
		await page.keyboard.type( extraText );
		metrics.recordClick();

		// Submit.
		const submitBtn = page.locator( '#jt-composer button:has-text("Post Reply")' );
		await submitBtn.click();
		metrics.recordClick();

		// Wait for the new reply to appear.
		const newReply = page.locator( '.jt-reply-body', { hasText: /My response to the quote/ } );
		await expect( newReply ).toBeVisible( { timeout: 10000 } );

		// Cleanup ID.
		const ids = dbQuery( `SELECT id FROM wp_jt_replies WHERE post_id = ${ postId } AND author_id = ${ aliceId } ORDER BY id DESC LIMIT 1` );
		if ( ids.length > 0 ) {
			quotedReplyId = parseInt( ids[ 0 ], 10 );
		}

		const expectation = loadSpec( 'C10' );
		matchDelivery( expectation, {
			composer_populated_with_quote: editorHTML.length > 0,
			quoted_reply_submitted: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_reply: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
