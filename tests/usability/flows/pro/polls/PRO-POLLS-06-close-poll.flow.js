// @ts-check
/**
 * PRO-POLLS-06 — Close poll.
 *
 * The post author (bob) manually closes a poll via the UI, and alice
 * can no longer vote. Verifies the DB status flips to 'closed'.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-06 — Close poll', () => {

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
			'--author=4', // bob
			`--title=Close Poll ${ suffix }`,
			'--content=Poll to close.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=single',
			'--options=Yes,No',
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

	test.fixme( 'bob closes the poll, alice cannot vote', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Login as bob (post author) to close the poll.
		await autoLogin( page, 'bob', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		const pollWidget = page.locator( '.jt-poll' );
		await expect( pollWidget ).toBeVisible( { timeout: 5000 } );

		const closeBtn = pollWidget.locator( 'button:has-text("Close Poll")' );
		await expect( closeBtn ).toBeVisible( { timeout: 5000 } );
		await closeBtn.click();
		metrics.recordClick();

		// DB: poll status is 'closed'.
		const status = dbQuery( `SELECT status FROM wp_jt_pro_polls WHERE id = ${ pollId }` );
		expect( status[ 0 ] ).toBe( 'closed' );

		// Now visit as alice — vote button should not be present.
		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		const voteBtn = page.locator( '.jt-poll button:has-text("Vote")' );
		await expect( voteBtn ).toBeHidden( { timeout: 5000 } );

		// Closed label should be visible.
		const closedLabel = page.locator( '.jt-poll .jt-poll-closed, .jt-poll:has-text("Closed")' );
		await expect( closedLabel ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
