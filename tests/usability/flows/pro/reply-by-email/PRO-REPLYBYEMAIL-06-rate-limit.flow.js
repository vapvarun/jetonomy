// @ts-check
/**
 * PRO-REPLYBYEMAIL-06 — Rate limit enforcement.
 *
 * Rapidly processes multiple inbound emails from the same user and
 * verifies that rate limiting kicks in after the threshold.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-REPLYBYEMAIL-06 — Rate limit enforcement', () => {

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
			'--title=Rate limit test',
			'--content=Rate limit test body',
		] );
		postId = post.data?.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test.fixme( 'rate limit rejects excessive inbound emails', () => {
		// Generate multiple tokens for the same user.
		const results = [];
		for ( let i = 0; i < 10; i++ ) {
			const tokenResult = proJourney( [
				'reply-by-email', 'generate-token',
				`--post_id=${ postId }`,
				'--user_id=1',
			] );
			try {
				const inbound = proJourney( [
					'reply-by-email', 'process-inbound',
					`--token=${ tokenResult.data?.token }`,
					`--body=Reply number ${ i + 1 }`,
					'--from=admin@forums.local',
				] );
				results.push( inbound.success );
			} catch ( e ) {
				results.push( false );
			}
		}

		// At least some should be rejected due to rate limiting.
		const rejected = results.filter( ( r ) => ! r ).length;
		expect( rejected ).toBeGreaterThan( 0 );
	} );
} );
