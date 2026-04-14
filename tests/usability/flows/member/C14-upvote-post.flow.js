// @ts-check
/**
 * C14 — Upvote a post.
 *
 * Seeds a post as bob, logs in as alice, visits the single-post page,
 * clicks the upvote button, and asserts the score increments and the
 * .voted class appears.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C14 — Upvote a post', () => {

	const spaceId = getSpaceId( 'welcome' );
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;
	const aliceId = getUserId( 'alice' );
	const bobId = getUserId( 'bob' );

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=C14 Upvote Post ${ suffix }`,
			'--content=Post to upvote.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Clear any existing vote by alice on this post.
		try {
			journey( [ 'vote', 'cast', `--voter=${ aliceId }`, '--type=post', `--id=${ postId }`, '--value=1' ] );
			journey( [ 'vote', 'cast', `--voter=${ aliceId }`, '--type=post', `--id=${ postId }`, '--value=1' ] );
		} catch ( e ) { /* ignore — ensures no existing vote */ }
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice upvotes a post and score increments', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Get the initial score from the vote counter.
		const scoreEl = page.locator( '.jt-post-foot .jt-act .n' ).first();
		await expect( scoreEl ).toBeVisible( { timeout: 5000 } );
		const scoreBefore = parseInt( ( await scoreEl.textContent() )?.trim() || '0', 10 );

		// Click the upvote button (first .jt-act button in .jt-post-foot).
		const upvoteBtn = page.locator( '.jt-post-foot button[data-wp-on--click="actions.voteUp"]' );
		await expect( upvoteBtn ).toBeVisible();
		await upvoteBtn.click();
		metrics.recordClick();

		// Assert score incremented.
		await expect( scoreEl ).toHaveText( String( scoreBefore + 1 ), { timeout: 5000 } );

		// Assert the voted class appears on the upvote button.
		await expect( upvoteBtn ).toHaveClass( /voted/, { timeout: 5000 } );

		// DB: vote row exists.
		assertDbRowExists( 'wp_jt_votes', `user_id = ${ aliceId } AND target_type = 'post' AND target_id = ${ postId } AND value = 1` );

		const expectation = loadSpec( 'C14' );
		matchDelivery( expectation, {
			score_incremented: true,
			upvote_button_active: true,
			vote_persisted_in_db: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_vote: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
