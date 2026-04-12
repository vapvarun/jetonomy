// @ts-check
/**
 * PRO-REPLYBYEMAIL-03 — Inbound webhook receives email.
 *
 * Simulates an inbound email webhook delivery via CLI and verifies
 * the command processes without error.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-REPLYBYEMAIL-03 — Inbound webhook receives email', () => {

	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'reply-by-email' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'reply-by-email' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		// Create a post to reply to.
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Inbound webhook test post',
			'--content=Post for inbound email testing',
		] );
		postId = post.data?.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test.fixme( 'inbound email webhook creates a reply', () => {
		// Generate a token for the post.
		const tokenResult = proJourney( [
			'reply-by-email', 'generate-token',
			`--post_id=${ postId }`,
			'--user_id=1',
		] );
		expect( tokenResult.success ).toBe( true );
		const token = tokenResult.data?.token;

		// Simulate inbound email processing.
		const inbound = proJourney( [
			'reply-by-email', 'process-inbound',
			`--token=${ token }`,
			'--body=This is a reply sent via email',
			'--from=admin@forums.local',
		] );
		expect( inbound.success ).toBe( true );

		// Verify a reply was created.
		const replies = dbQuery(
			`SELECT COUNT(*) FROM wp_jt_replies WHERE post_id = ${ postId }`
		);
		expect( parseInt( replies[ 0 ], 10 ) ).toBeGreaterThanOrEqual( 1 );
	} );
} );
