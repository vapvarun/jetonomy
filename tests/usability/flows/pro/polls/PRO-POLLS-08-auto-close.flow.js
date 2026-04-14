// @ts-check
/**
 * PRO-POLLS-08 — Poll auto-closes at closes_at.
 *
 * Seeds a poll with closes_at set to the past, runs the cron evaluator,
 * and verifies the poll status flips to 'closed'.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-POLLS-08 — Poll auto-closes at closes_at', () => {

	const spaceId = 1;
	let postId;
	let pollId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=AutoClose ${ suffix }`,
			'--content=Auto-close test.',
		] );
		postId = post.data?.id || post.id;

		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=single',
			'--options=A,B',
		] );
		pollId = poll.data?.id;

		// Set closes_at to 1 hour ago to simulate expiry.
		if ( pollId ) {
			dbWrite( `UPDATE wp_jt_pro_polls SET closes_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ${ pollId }` );
		}
	} );

	test.afterEach( () => {
		if ( pollId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_poll_options WHERE poll_id = ${ pollId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_polls WHERE id = ${ pollId }` ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'cron closes expired poll automatically', async () => {
		// Trigger the cron event that evaluates poll closures.
		wp( [ 'cron', 'event', 'run', 'jetonomy_pro_polls_auto_close' ] );

		// DB: poll status should now be 'closed'.
		const status = dbQuery( `SELECT status FROM wp_jt_pro_polls WHERE id = ${ pollId }` );
		expect( status[ 0 ] ).toBe( 'closed' );
	} );
} );
