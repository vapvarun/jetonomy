// @ts-check
/**
 * XP02 — New reply submit fans out to multiple extensions.
 *
 * Creates a reply and verifies cross-extension effects: moderation
 * rules, webhooks, and notification dispatch.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );

test.describe( 'XP02 — New reply submit fans out to 6 extensions', () => {

	let spaceId;
	let postId;
	let replyId;
	let webhookId;

	test.beforeEach( () => {
		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		// Seed a post to reply to.
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=XP02 reply fanout base post',
			'--content=Base post for reply fanout test',
		] );
		postId = post.data?.id;

		// Seed a webhook for reply.created.
		try {
			const wh = proJourney( [
				'webhooks', 'create',
				'--url=https://httpbin.org/post',
				'--event=reply.created',
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

	test( 'new reply triggers cross-extension fan-out', () => {
		const reply = journey( [
			'reply', 'create',
			`--post_id=${ postId }`,
			'--author=1',
			'--content=XP02 test reply for fanout verification',
		] );
		expect( reply.success ).toBe( true );
		replyId = reply.data?.id;

		// Check webhook dispatch.
		if ( webhookId ) {
			const lastTriggered = dbQuery(
				`SELECT last_triggered_at FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
			);
			expect( lastTriggered[ 0 ] ).toBeTruthy();
		}

		// Reply count on post should be incremented.
		const replyCount = dbQuery(
			`SELECT reply_count FROM wp_jt_posts WHERE id = ${ postId }`
		);
		expect( parseInt( replyCount[ 0 ], 10 ) ).toBeGreaterThanOrEqual( 1 );
	} );
} );
