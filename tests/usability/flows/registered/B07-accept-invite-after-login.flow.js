// @ts-check
/**
 * B07 — Accept invite link after login (registered).
 *
 * Checks if an active invite link exists. If so, auto-logs in as bob,
 * visits the invite URL, and asserts the acceptance flow renders.
 * Skipped if no invite links exist.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'B07 — Accept invite link after login', () => {

	let inviteCode;

	test.beforeAll( () => {
		const codes = dbQuery( 'SELECT code FROM wp_jt_invite_links WHERE is_active = 1 LIMIT 1' );
		inviteCode = ( codes.length > 0 && codes[ 0 ] ) ? codes[ 0 ] : null;
	} );

	test( 'logged-in bob can visit invite link and see acceptance flow', async ( { page } ) => {
		test.skip( ! inviteCode, 'No active invite links in the database' );

		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'bob', `/community/invite/${ inviteCode }/` );
		metrics.start();

		// Page renders without a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// The invite page should show either:
		// 1. An acceptance confirmation / "You've joined" message.
		// 2. A "Join" button for the space.
		// 3. The space page itself (if auto-accepted).
		const inviteContent = page.locator(
			'.jt-app, .jt-container, .jt-invite, .jt-invite-landing, .jt-topics'
		);
		await expect( inviteContent.first() ).toBeVisible( { timeout: 8000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
