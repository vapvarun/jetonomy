// @ts-check
/**
 * C09 — Reply to a reply (threaded).
 *
 * Seeds a post with a reply, logs in as alice, clicks the "Reply" button
 * on the existing reply, types a nested reply, submits, and asserts the
 * threaded reply appears with nesting indentation.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C09 — Reply to a reply (threaded)', () => {

	const spaceId = users.spaceId( 'welcome' );
	const spaceSlug = 'welcome';
	const aliceId = users.id( 'alice' );
	const bobId = users.id( 'bob' );
	let postId;
	let postSlug;
	let parentReplyId;
	let nestedReplyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=C09 Post ${ suffix }`,
			'--content=Post for threaded reply test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Seed a top-level reply from bob.
		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ bobId }`,
			`--content=Top-level reply from bob ${ suffix }`,
		] );
		parentReplyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		if ( nestedReplyId ) {
			try { journey( [ 'reply', 'delete', String( nestedReplyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( parentReplyId ) {
			try { journey( [ 'reply', 'delete', String( parentReplyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice replies to an existing reply (nested thread)', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const nestedText = `C09 Nested Reply ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Find the "Reply" button on the parent reply.
		const parentReply = page.locator( `.jt-reply` ).first();
		await expect( parentReply ).toBeVisible( { timeout: 5000 } );

		const replyBtn = parentReply.locator( '.jt-reply-to-btn' );
		await expect( replyBtn ).toBeVisible();
		await replyBtn.click();
		metrics.recordClick();

		// An inline composer should appear. Type into it.
		const inlineEditor = page.locator( '.jt-editor-body[contenteditable="true"]' ).last();
		await expect( inlineEditor ).toBeVisible( { timeout: 5000 } );
		await inlineEditor.click();
		await page.keyboard.type( nestedText );
		metrics.recordClick();

		// Submit the nested reply.
		const submitBtn = page.locator( 'button:has-text("Post Reply")' ).last();
		await submitBtn.click();
		metrics.recordClick();

		// Wait for the nested reply to appear.
		const nestedReply = page.locator( '.jt-reply-body', { hasText: nestedText } );
		await expect( nestedReply ).toBeVisible( { timeout: 10000 } );

		// The nested reply should be inside a jt-nested wrapper.
		const nestedWrapper = page.locator( '.jt-nested' );
		const hasNesting = await nestedWrapper.count();
		expect( hasNesting ).toBeGreaterThan( 0 );

		// Grab ID for cleanup.
		const ids = dbQuery( `SELECT id FROM wp_jt_replies WHERE post_id = ${ postId } AND author_id = ${ aliceId } AND parent_id = ${ parentReplyId } ORDER BY id DESC LIMIT 1` );
		if ( ids.length > 0 ) {
			nestedReplyId = parseInt( ids[ 0 ], 10 );
		}

		assertDbRowExists( 'wp_jt_replies', `post_id = ${ postId } AND author_id = ${ aliceId } AND parent_id = ${ parentReplyId }` );

		const expectation = loadSpec( 'C09' );
		matchDelivery( expectation, {
			nested_reply_visible: true,
			nesting_indentation_present: hasNesting > 0,
			reply_has_correct_parent_id: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_reply: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
