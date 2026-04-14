// @ts-check
/**
 * PRO-POLLS-04 — See live tallies + percentages.
 *
 * Seeds a poll with existing votes, visits the post, and asserts
 * tallies and percentage bars are displayed correctly.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-04 — See live tallies + percentages', () => {

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
			`--title=Tallies Post ${ suffix }`,
			'--content=Check tallies.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=single',
			'--options=Yes,No,Maybe',
		] );
		pollId = poll.data?.id;

		// Seed votes: bob votes Yes, admin votes No.
		if ( pollId ) {
			const optIds = dbQuery( `SELECT id FROM wp_jt_pro_poll_options WHERE poll_id = ${ pollId } ORDER BY id ASC` );
			if ( optIds.length >= 2 ) {
				dbWrite( `INSERT INTO wp_jt_pro_poll_votes (poll_id, option_id, user_id) VALUES (${ pollId }, ${ optIds[ 0 ] }, 4)` );
				dbWrite( `INSERT INTO wp_jt_pro_poll_votes (poll_id, option_id, user_id) VALUES (${ pollId }, ${ optIds[ 1 ] }, 1)` );
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

	test.fixme( 'alice sees vote tallies and percentage bars', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Alice votes first so she can see results.
		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		const pollWidget = page.locator( '.jt-poll' );
		await expect( pollWidget ).toBeVisible( { timeout: 5000 } );

		// Vote to unlock results view.
		const firstOption = pollWidget.locator( 'input[type="radio"], .jt-poll-option' ).first();
		await firstOption.click();
		metrics.recordClick();
		await pollWidget.locator( 'button:has-text("Vote")' ).click();
		metrics.recordClick();

		// Percentage text should be visible.
		const percentages = pollWidget.locator( '.jt-poll-percentage, .jt-poll-bar-label' );
		await expect( percentages.first() ).toBeVisible( { timeout: 5000 } );

		// Total vote count display.
		const totalEl = pollWidget.locator( '.jt-poll-total, .jt-poll-vote-count' );
		await expect( totalEl ).toBeVisible( { timeout: 5000 } );
		const totalText = await totalEl.textContent();
		expect( parseInt( totalText?.replace( /\D/g, '' ) || '0', 10 ) ).toBeGreaterThanOrEqual( 3 );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
