// @ts-check
/**
 * C15 — Downvote a post.
 *
 * Seeds a post as bob, logs in as alice, visits the single-post page,
 * clicks the downvote button, and asserts the score decrements and the
 * .voted class appears on the downvote button.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C15 — Downvote a post', () => {

	const spaceId = getSpaceId( 'welcome' );
	const spaceSlug = 'welcome';
	const aliceId = getUserId( 'alice' );
	const bobId = getUserId( 'bob' );
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=C15 Downvote Post ${ suffix }`,
			'--content=Post to downvote.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice downvotes a post and score decrements', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Get initial score.
		const scoreEl = page.locator( '.jt-post-foot .jt-act .n' ).first();
		await expect( scoreEl ).toBeVisible( { timeout: 5000 } );
		const scoreBefore = parseInt( ( await scoreEl.textContent() )?.trim() || '0', 10 );

		// Click the downvote button.
		const downvoteBtn = page.locator( '.jt-post-foot button[data-wp-on--click="actions.voteDown"]' );
		await expect( downvoteBtn ).toBeVisible();
		await downvoteBtn.click();
		metrics.recordClick();

		// Assert score decremented.
		await expect( scoreEl ).toHaveText( String( scoreBefore - 1 ), { timeout: 5000 } );

		// Assert the voted class appears on the downvote button.
		await expect( downvoteBtn ).toHaveClass( /voted/, { timeout: 5000 } );

		// DB: vote row exists with value = -1.
		assertDbRowExists( 'wp_jt_votes', `user_id = ${ aliceId } AND target_type = 'post' AND target_id = ${ postId } AND value = -1` );

		const expectation = loadSpec( 'C15' );
		matchDelivery( expectation, {
			score_decremented: true,
			downvote_button_active: true,
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
