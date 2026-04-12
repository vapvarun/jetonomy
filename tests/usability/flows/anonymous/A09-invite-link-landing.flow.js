// @ts-check
/**
 * A09 — Invite link landing page (anonymous).
 *
 * Checks if an invite link exists in the DB. If so, visits it and asserts
 * the invite landing page renders. If no invites exist, the test is skipped.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A09 — Land on invite link', () => {

	let inviteCode;

	test.beforeAll( () => {
		const codes = dbQuery( 'SELECT code FROM wp_jt_invite_links WHERE is_active = 1 LIMIT 1' );
		inviteCode = ( codes.length > 0 && codes[ 0 ] ) ? codes[ 0 ] : null;
	} );

	test( 'anonymous visitor sees invite landing page', async ( { page } ) => {
		test.skip( ! inviteCode, 'No active invite links in the database' );

		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/invite/${ inviteCode }/` );

		// Page renders without a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// The invite landing page has some call to action or info.
		const container = page.locator( '.jt-app, .jt-container, .jt-invite, .jt-invite-landing' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		const expectation = loadSpec( 'A09' );
		matchDelivery( expectation, {
			page_renders: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
