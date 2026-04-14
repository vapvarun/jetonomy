// @ts-check
/**
 * D04 — Downvote without restriction (P2).
 *
 * Alice at TL3 downvotes a post by bob. Asserts the downvote succeeds
 * with no rate limit error and the vote score decrements.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'D04 — Downvote without restriction', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const aliceId = 3;
	const bobId = 4;
	let postId;
	let postSlug;

	test.beforeEach( () => {
		// Set alice to TL3 so downvoting is unrestricted.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 3 WHERE user_id = ${ aliceId }` );

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=D04 Downvote Post ${ suffix }`,
			'--content=Post to downvote without restriction.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Clear any existing votes by alice on this post.
		dbWrite( `DELETE FROM wp_jt_votes WHERE user_id = ${ aliceId } AND target_type = 'post' AND target_id = ${ postId }` );
	} );

	test.afterEach( () => {
		if ( postId ) {
			dbWrite( `DELETE FROM wp_jt_votes WHERE target_type = 'post' AND target_id = ${ postId }` );
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test( 'alice at TL3 downvotes a post and score decrements', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Get the initial score.
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
		assertDbRowExists(
			'wp_jt_votes',
			`user_id = ${ aliceId } AND target_type = 'post' AND target_id = ${ postId } AND value = -1`
		);

		// Assert no rate limit error appeared on the page.
		const errorToast = page.locator( '.jt-toast-error, .jt-rate-limit-error' );
		await expect( errorToast ).toHaveCount( 0 );

		const expectation = loadSpec( 'D04' );
		matchDelivery( expectation, {
			score_decremented: true,
			downvote_active: true,
			vote_in_db: true,
			no_rate_limit_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
