// @ts-check
/**
 * PRO-REPLYBYEMAIL-05 — Body parsing strips quoted lines.
 *
 * Sends an inbound email with quoted reply content ("> On Mon..."
 * lines) and verifies the reply body has the quoted lines stripped.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-REPLYBYEMAIL-05 — Body parsing strips quoted lines', () => {

	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'reply-by-email' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'reply-by-email' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Body parsing test',
			'--content=Original post content',
		] );
		postId = post.data?.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test.fixme( 'quoted lines are stripped from email body', () => {
		const tokenResult = proJourney( [
			'reply-by-email', 'generate-token',
			`--post_id=${ postId }`,
			'--user-id=1',
		] );
		const token = tokenResult.data?.token;

		const emailBody = [
			'This is my actual reply.',
			'',
			'> On Mon, Apr 6 2026, admin wrote:',
			'> Original post content',
			'> ---',
			'> Reply above this line.',
		].join( '\n' );

		const inbound = proJourney( [
			'reply-by-email', 'process-inbound',
			`--token=${ token }`,
			`--body=${ emailBody }`,
			'--from=admin@forums.local',
		] );
		expect( inbound.success ).toBe( true );

		// The stored reply should contain only the actual reply, not quoted lines.
		const replyContent = dbQuery(
			`SELECT content FROM wp_jt_replies WHERE post_id = ${ postId } ORDER BY id DESC LIMIT 1`
		);
		expect( replyContent[ 0 ] ).toContain( 'actual reply' );
		expect( replyContent[ 0 ] ).not.toContain( 'On Mon' );
	} );
} );
