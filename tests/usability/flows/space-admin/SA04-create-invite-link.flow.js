// @ts-check
/**
 * SA04 — Create an invite link.
 *
 * Logs in as admin, visits the space edit page, finds the invite links
 * section, creates a new invite link, and asserts a DB row is written.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'SA04 — Create an invite link', () => {

	let fixtureSpaceId;

	test.beforeAll( () => {
		const rows = dbQuery( 'SELECT id FROM wp_jt_spaces ORDER BY id ASC LIMIT 1' );
		fixtureSpaceId = rows.length > 0 ? parseInt( rows[ 0 ], 10 ) : 1;
	} );

	test.fixme( 'admin creates an invite link via space edit page', async ( { page } ) => {
		// FIXME: space-edit admin view does not yet expose an "invite_links" tab or UI.
		const metrics = new EaseMetrics( page );

		// Navigate to the space edit page, invite links tab.
		await autoLogin( page, 1, `/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ fixtureSpaceId }&tab=invite_links` );
		metrics.start();

		// Assert the invite links section renders.
		const section = page.locator( '.jetonomy-invite-links, #jetonomy-invite-links, [data-tab="invite_links"]' );
		await expect( section.or( page.locator( 'h2:has-text("Invite"), h3:has-text("Invite")' ) ) ).toBeVisible( { timeout: 5000 } );

		// Click the create invite link button.
		const createBtn = page.locator( 'button:has-text("Create"), a:has-text("Create Invite"), button:has-text("Generate")' );
		await expect( createBtn.first() ).toBeVisible( { timeout: 5000 } );
		await createBtn.first().click();
		metrics.recordClick();

		// Wait for the new link to appear in the list.
		await page.waitForTimeout( 1000 );

		// Assert DB row exists for invite link.
		assertDbRowExists( 'wp_jt_invite_links', `space_id = ${ fixtureSpaceId }` );

		const expectation = loadSpec( 'SA04' );
		matchDelivery( expectation, {
			invite_link_db_row_created: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_goal: metrics.clicks,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
