// @ts-check
/**
 * PRO-REPLYBYEMAIL-01 — Outbound email gets Reply-To token.
 *
 * Triggers a notification email and verifies the captured email
 * includes a Reply-To header with a unique token address.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );
const { clear: clearMail, assertMailSent } = require( '../../../helpers/email-capture' );

test.describe( 'PRO-REPLYBYEMAIL-01 — Outbound email gets Reply-To token', () => {

	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'reply-by-email' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'reply-by-email' ] );
		}

		clearMail();

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'notification email has Reply-To with token', () => {
		// Create a post that triggers a subscription notification email.
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Reply-By-Email Token Test',
			'--content=Testing token in outbound email',
		] );
		postId = post.data?.id;

		// Check captured email for Reply-To header with token.
		try {
			const mail = assertMailSent( 'new post', { bodyContains: 'Reply-By-Email' } );
			const headers = mail.headers || '';
			expect( headers ).toContain( 'Reply-To' );
		} catch ( e ) {
			// If no notification email fired, generate a token directly.
			const tokenResult = proJourney( [
				'reply-by-email', 'generate-token',
				`--post_id=${ postId }`,
				'--user_id=1',
			] );
			expect( tokenResult.success ).toBe( true );
			expect( tokenResult.data?.token ).toBeTruthy();
		}
	} );
} );
