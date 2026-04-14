// @ts-check
/**
 * PRO-POLLS-03 — Vote multi-choice poll.
 *
 * Seeds a multi-choice poll, alice selects 2 options, submits, and
 * asserts both votes are persisted.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-03 — Vote multi-choice poll', () => {

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
			`--title=Multi Poll ${ suffix }`,
			'--content=Pick multiple.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=multi',
			'--options=Red,Green,Blue,Yellow',
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

	test.fixme( 'alice votes for two options in a multi-choice poll', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		const pollWidget = page.locator( '.jt-poll' );
		await expect( pollWidget ).toBeVisible( { timeout: 5000 } );

		// Select two checkboxes (multi-choice).
		const options = pollWidget.locator( 'input[type="checkbox"], .jt-poll-option' );
		await options.nth( 0 ).click();
		metrics.recordClick();
		await options.nth( 2 ).click();
		metrics.recordClick();

		// Submit.
		const voteBtn = pollWidget.locator( 'button:has-text("Vote")' );
		await voteBtn.click();
		metrics.recordClick();

		// Results appear.
		await expect( pollWidget.locator( '.jt-poll-results, .jt-poll-bar' ).first() ).toBeVisible( { timeout: 5000 } );

		// DB: 2 vote rows for alice.
		const voteCount = dbQuery( `SELECT COUNT(*) FROM wp_jt_pro_poll_votes WHERE poll_id = ${ pollId } AND user_id = 3` );
		expect( parseInt( voteCount[ 0 ], 10 ) ).toBe( 2 );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
