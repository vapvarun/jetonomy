// @ts-check
/**
 * PRO-REPLYBYEMAIL-07 — Expired token rejection.
 *
 * Generates a token, artificially expires it in the DB, then attempts
 * to use it and verifies rejection.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-REPLYBYEMAIL-07 — Expired token rejection', () => {

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
			'--title=Expired token test',
			'--content=Test expired token',
		] );
		postId = post.data?.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test.fixme( 'expired token is rejected', () => {
		const tokenResult = proJourney( [
			'reply-by-email', 'generate-token',
			`--post_id=${ postId }`,
			'--user_id=1',
		] );
		const token = tokenResult.data?.token;
		expect( token ).toBeTruthy();

		// Expire the token by backdating its expiry in the DB.
		dbWrite(
			`UPDATE wp_jt_pro_reply_tokens SET expires_at = '2020-01-01 00:00:00' WHERE token = '${ token }'`
		);

		// Attempt to validate — should fail.
		let result;
		try {
			result = proJourney( [
				'reply-by-email', 'validate-token',
				`--token=${ token }`,
			] );
		} catch ( e ) {
			result = { success: false };
		}
		expect( result.success ).toBe( false );
	} );
} );
