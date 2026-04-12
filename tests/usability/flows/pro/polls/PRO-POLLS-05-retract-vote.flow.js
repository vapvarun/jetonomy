// @ts-check
/**
 * PRO-POLLS-05 — Retract vote.
 *
 * Seeds a poll where alice has already voted, visits the post, clicks
 * retract/change vote, and asserts the vote is removed from DB.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-05 — Retract vote', () => {

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
			`--title=Retract Test ${ suffix }`,
			'--content=Retract your vote.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=single',
			'--options=A,B',
		] );
		pollId = poll.data?.id;

		// Alice already voted for option A.
		if ( pollId ) {
			const optIds = dbQuery( `SELECT id FROM wp_jt_pro_poll_options WHERE poll_id = ${ pollId } ORDER BY id ASC LIMIT 1` );
			if ( optIds.length > 0 ) {
				dbWrite( `INSERT INTO wp_jt_pro_poll_votes (poll_id, option_id, user_id) VALUES (${ pollId }, ${ optIds[ 0 ] }, 3)` );
			}
		}
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

	test.fixme( 'alice retracts her vote', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		const pollWidget = page.locator( '.jt-poll' );
		await expect( pollWidget ).toBeVisible( { timeout: 5000 } );

		// Click retract/change vote button.
		const retractBtn = pollWidget.locator( 'button:has-text("Retract"), button:has-text("Change Vote")' );
		await expect( retractBtn ).toBeVisible( { timeout: 5000 } );
		await retractBtn.click();
		metrics.recordClick();

		// The poll should return to voting state (options visible again).
		const options = pollWidget.locator( 'input[type="radio"], .jt-poll-option' );
		await expect( options.first() ).toBeVisible( { timeout: 5000 } );

		// DB: vote removed.
		assertDbRowAbsent( 'wp_jt_pro_poll_votes', `poll_id = ${ pollId } AND user_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
