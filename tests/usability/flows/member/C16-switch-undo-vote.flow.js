// @ts-check
/**
 * C16 — Switch / undo vote.
 *
 * Seeds a post as bob, logs in as alice. Tests three vote transitions:
 * 1. Upvote (+1), then upvote again (undo, returns to 0).
 * 2. Downvote (-1), then downvote again (undo, returns to 0).
 * 3. Upvote (+1), then downvote (switch, goes to -1).
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C16 — Switch / undo vote', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4', // bob
			`--title=C16 Vote Toggle ${ suffix }`,
			'--content=Post for vote toggle test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice upvotes, undoes, then downvotes (vote state transitions)', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		const scoreEl = page.locator( '.jt-post-foot .jt-act .n' ).first();
		await expect( scoreEl ).toBeVisible( { timeout: 5000 } );
		const baseScore = parseInt( ( await scoreEl.textContent() )?.trim() || '0', 10 );

		const upvoteBtn = page.locator( '.jt-post-foot button[data-wp-on--click="actions.voteUp"]' );
		const downvoteBtn = page.locator( '.jt-post-foot button[data-wp-on--click="actions.voteDown"]' );

		// Step 1: Upvote.
		await upvoteBtn.click();
		metrics.recordClick();
		await expect( scoreEl ).toHaveText( String( baseScore + 1 ), { timeout: 5000 } );
		await expect( upvoteBtn ).toHaveClass( /voted/, { timeout: 3000 } );

		// Step 2: Upvote again (undo).
		await upvoteBtn.click();
		metrics.recordClick();
		await expect( scoreEl ).toHaveText( String( baseScore ), { timeout: 5000 } );
		await expect( upvoteBtn ).not.toHaveClass( /voted/, { timeout: 3000 } );

		// Step 3: Downvote.
		await downvoteBtn.click();
		metrics.recordClick();
		await expect( scoreEl ).toHaveText( String( baseScore - 1 ), { timeout: 5000 } );
		await expect( downvoteBtn ).toHaveClass( /voted/, { timeout: 3000 } );

		const expectation = loadSpec( 'C16' );
		matchDelivery( expectation, {
			upvote_increments_score: true,
			second_upvote_undoes_vote: true,
			downvote_decrements_score: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_complete: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
