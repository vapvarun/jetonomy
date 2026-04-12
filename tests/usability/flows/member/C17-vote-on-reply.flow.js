// @ts-check
/**
 * C17 — Vote on a reply.
 *
 * Seeds a post with a reply by bob, logs in as alice, upvotes the reply,
 * and asserts the score increments and the .voted class appears on the
 * reply's upvote button.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C17 — Vote on a reply', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4', // bob
			`--title=C17 Reply Vote Post ${ suffix }`,
			'--content=Post with a reply to vote on.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			'--author=4',
			`--content=Reply to upvote ${ suffix }`,
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
	} );

	test( 'alice upvotes a reply and score increments', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Locate the reply's upvote button and score.
		const replyCard = page.locator( '.jt-reply' ).first();
		await expect( replyCard ).toBeVisible( { timeout: 5000 } );

		const upvoteBtn = replyCard.locator( 'button[data-wp-on--click="actions.voteReplyUp"]' );
		const scoreEl = replyCard.locator( '.jt-reply-foot .jt-act .n' ).first();
		await expect( upvoteBtn ).toBeVisible();
		await expect( scoreEl ).toBeVisible();

		const scoreBefore = parseInt( ( await scoreEl.textContent() )?.trim() || '0', 10 );

		// Click upvote.
		await upvoteBtn.click();
		metrics.recordClick();

		// Assert score incremented.
		await expect( scoreEl ).toHaveText( String( scoreBefore + 1 ), { timeout: 5000 } );

		// Assert voted class on the button.
		await expect( upvoteBtn ).toHaveClass( /voted/, { timeout: 5000 } );

		// DB: vote row exists for the reply.
		assertDbRowExists( 'wp_jt_votes', `user_id = 3 AND target_type = 'reply' AND target_id = ${ replyId } AND value = 1` );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
