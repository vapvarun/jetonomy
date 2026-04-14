// @ts-check
/**
 * C08 — Reply to a post (flat).
 *
 * Seeds a post as bob, logs in as alice, visits it, types a reply in the
 * composer, submits, and asserts the reply appears in the thread.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C08 — Reply to a post (flat)', () => {

	const spaceId = users.spaceId( 'welcome' );
	const spaceSlug = 'welcome';
	const aliceId = users.id( 'alice' );
	const bobId = users.id( 'bob' );
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=C08 Post ${ suffix }`,
			'--content=Post that will receive a reply.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( replyId ) {
			try { journey( [ 'reply', 'delete', String( replyId ) ] ); } catch ( e ) { /* ignore */ }
			replyId = null;
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice replies to a post', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const replyText = `C08 Reply ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Scroll to the composer at the bottom.
		const composer = page.locator( '#jt-composer' );
		await expect( composer ).toBeVisible( { timeout: 5000 } );

		// Type into the contenteditable reply editor.
		const editorBody = composer.locator( '.jt-editor-body[contenteditable="true"]' );
		await expect( editorBody ).toBeVisible();
		await editorBody.click();
		await page.keyboard.type( replyText );
		metrics.recordClick();

		// Submit the reply.
		const submitBtn = composer.locator( 'button:has-text("Post Reply")' );
		await expect( submitBtn ).toBeVisible();
		await submitBtn.click();
		metrics.recordClick();

		// Wait for the reply to appear in the thread.
		const newReply = page.locator( '.jt-reply-body', { hasText: replyText } );
		await expect( newReply ).toBeVisible( { timeout: 10000 } );

		// Grab ID for cleanup.
		const ids = dbQuery( `SELECT id FROM wp_jt_replies WHERE post_id = ${ postId } AND author_id = ${ aliceId } ORDER BY id DESC LIMIT 1` );
		if ( ids.length > 0 ) {
			replyId = parseInt( ids[ 0 ], 10 );
		}

		assertDbRowExists( 'wp_jt_replies', `post_id = ${ postId } AND author_id = ${ aliceId }` );

		const expectation = loadSpec( 'C08' );
		matchDelivery( expectation, {
			reply_visible_in_thread: true,
			reply_created_in_db: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_reply: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
