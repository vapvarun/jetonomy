// @ts-check
/**
 * PRO-POLLS-02 — Vote single-choice poll.
 *
 * Seeds a post with a single-choice poll, logs in as alice, selects
 * an option, clicks vote, and asserts the vote is persisted.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-02 — Vote single-choice poll', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let postId;
	let postSlug;
	let pollId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=Poll Vote Test ${ suffix }`,
			'--content=Vote below.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Seed poll via proJourney.
		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=single',
			'--options=Alpha,Beta,Gamma',
		] );
		pollId = poll.data?.id;
	} );

	test.afterEach( () => {
		if ( pollId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_poll_votes WHERE poll_id = ${ pollId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_poll_options WHERE poll_id = ${ pollId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_polls WHERE id = ${ pollId }` ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice votes on a single-choice poll', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Poll widget should be visible.
		const pollWidget = page.locator( '.jt-poll' );
		await expect( pollWidget ).toBeVisible( { timeout: 5000 } );

		// Select the first option (radio button for single-choice).
		const firstOption = pollWidget.locator( 'input[type="radio"], .jt-poll-option' ).first();
		await firstOption.click();
		metrics.recordClick();

		// Click vote button.
		const voteBtn = pollWidget.locator( 'button:has-text("Vote")' );
		await voteBtn.click();
		metrics.recordClick();

		// Results should appear.
		const results = pollWidget.locator( '.jt-poll-results, .jt-poll-bar' );
		await expect( results.first() ).toBeVisible( { timeout: 5000 } );

		// DB: vote row.
		assertDbRowExists( 'wp_jt_pro_poll_votes', `poll_id = ${ pollId } AND user_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
