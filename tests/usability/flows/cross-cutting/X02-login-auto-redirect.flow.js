// @ts-check
/**
 * X02 — Login auto-redirect
 *
 * Login as a non-admin user (bob) and check if the redirect goes to /community/.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'X02 — Login auto-redirect to /community/', () => {

	test( 'subscriber login lands on /community/', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Ensure a test user exists. Use 'bob' or fall back to creating one.
		let userLogin = 'bob';
		try {
			wp( [ 'user', 'get', userLogin, '--field=ID' ] );
		} catch ( e ) {
			// bob does not exist — create a subscriber.
			wp( [ 'user', 'create', 'bob', 'bob@example.com', '--role=subscriber', '--user_pass=password' ] );
		}

		await autoLogin( page, userLogin, '/community/' );
		metrics.start();

		// After login, the URL should contain /community/.
		const url = page.url();
		expect( url ).toContain( '/community' );

		metrics.assertErrorCount( 0 );
	} );
} );
