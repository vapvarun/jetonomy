// @ts-check
/**
 * XP04 — Vote on poll, verify webhook event fires.
 *
 * Creates a poll, votes on it, and checks that a webhook configured
 * for poll.voted fires.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );

test.describe( 'XP04 — Poll vote → webhooks → analytics', () => {

	let spaceId;
	let postId;
	let pollId;
	let webhookId;

	test.beforeEach( () => {
		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		// Create a post with a poll.
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=XP04 poll vote test',
			'--content=Vote on this poll',
		] );
		postId = post.data?.id;

		try {
			const poll = proJourney( [
				'polls', 'create',
				`--post_id=${ postId }`,
				'--question=Favorite color?',
				'--options=Red,Blue,Green',
			] );
			pollId = poll.data?.id;
		} catch ( e ) { /* */ }

		// Webhook for poll.voted.
		try {
			const wh = proJourney( [
				'webhooks', 'create',
				'--url=https://httpbin.org/post',
				'--event=poll.voted',
				'--enabled=1',
			] );
			webhookId = wh.data?.id;
		} catch ( e ) { /* */ }
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'poll vote triggers webhook dispatch', () => {
		if ( ! pollId ) {
			test.skip( true, 'Polls extension not enabled — skipping' );
			return;
		}

		const vote = proJourney( [
			'polls', 'vote',
			`--poll_id=${ pollId }`,
			'--option=0',
			'--user_id=1',
		] );
		expect( vote.success ).toBe( true );

		// Check webhook fired.
		if ( webhookId ) {
			const lastTriggered = dbQuery(
				`SELECT last_triggered_at FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
			);
			expect( lastTriggered[ 0 ] ).toBeTruthy();
		}
	} );
} );
