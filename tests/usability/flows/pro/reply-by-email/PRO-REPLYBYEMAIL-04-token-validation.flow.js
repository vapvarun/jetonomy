// @ts-check
/**
 * PRO-REPLYBYEMAIL-04 — Token validation.
 *
 * Generates a token and validates it can be decoded back to the
 * correct post and user. Also tests that an invalid token is rejected.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-REPLYBYEMAIL-04 — Token validation', () => {

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
			'--title=Token validation test',
			'--content=Token test body',
		] );
		postId = post.data?.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'valid token decodes to correct post and user', () => {
		const tokenResult = proJourney( [
			'reply-by-email', 'generate-token',
			`--post_id=${ postId }`,
			'--user_id=1',
		] );
		expect( tokenResult.success ).toBe( true );
		const token = tokenResult.data?.token;
		expect( token ).toBeTruthy();

		// Validate the token.
		const validation = proJourney( [
			'reply-by-email', 'validate-token',
			`--token=${ token }`,
		] );
		expect( validation.success ).toBe( true );
		expect( String( validation.data?.post_id ) ).toBe( String( postId ) );
		expect( String( validation.data?.user_id ) ).toBe( '1' );
	} );

	test( 'invalid token is rejected', () => {
		let result;
		try {
			result = proJourney( [
				'reply-by-email', 'validate-token',
				'--token=invalid-garbage-token-12345',
			] );
		} catch ( e ) {
			result = { success: false };
		}
		expect( result.success ).toBe( false );
	} );
} );
