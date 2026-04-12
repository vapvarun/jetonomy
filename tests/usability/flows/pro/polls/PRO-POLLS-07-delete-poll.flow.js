// @ts-check
/**
 * PRO-POLLS-07 — Delete poll.
 *
 * The post author deletes a poll via the UI or CLI, and verifies the
 * poll, options, and votes are all removed from the database.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-07 — Delete poll', () => {

	const spaceId = 1;
	let postId;
	let pollId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=Delete Poll ${ suffix }`,
			'--content=Poll to delete.',
		] );
		postId = post.data?.id || post.id;

		const poll = proJourney( [
			'polls', 'create',
			`--post=${ postId }`,
			'--type=single',
			'--options=X,Y',
		] );
		pollId = poll.data?.id;

		// Add a vote so we verify cascade delete.
		if ( pollId ) {
			const optIds = dbQuery( `SELECT id FROM wp_jt_pro_poll_options WHERE poll_id = ${ pollId } LIMIT 1` );
			if ( optIds.length > 0 ) {
				dbWrite( `INSERT INTO wp_jt_pro_poll_votes (poll_id, option_id, user_id) VALUES (${ pollId }, ${ optIds[ 0 ] }, 4)` );
			}
		}
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'deleting a poll removes poll, options, and votes', async () => {
		// Use CLI to delete the poll.
		proJourney( [ 'polls', 'delete', String( pollId ) ] );

		// DB: all poll data removed.
		assertDbRowAbsent( 'wp_jt_pro_polls', `id = ${ pollId }` );
		assertDbRowAbsent( 'wp_jt_pro_poll_options', `poll_id = ${ pollId }` );
		assertDbRowAbsent( 'wp_jt_pro_poll_votes', `poll_id = ${ pollId }` );
	} );
} );
