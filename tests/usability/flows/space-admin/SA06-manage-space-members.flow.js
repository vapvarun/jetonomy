// @ts-check
/**
 * SA06 — Manage space members.
 *
 * Logs in as admin, visits the space members tab, asserts member list
 * renders with role and kick controls visible.
 */

const { test, expect } = require( '@playwright/test' );
const { journey } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'SA06 — Manage space members', () => {

	let fixtureSpaceId;

	test.beforeAll( () => {
		const result = journey( [ 'space', 'list', '--category=1', '--limit=1' ] );
		fixtureSpaceId = result.data?.items?.[ 0 ]?.id ?? 1;
	} );

	test( 'admin sees member list with role and kick controls', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ fixtureSpaceId }&tab=members` );
		metrics.start();

		// Assert member list table or container renders.
		const memberList = page.locator( 'table.jetonomy-members, .jetonomy-member-list, [data-tab="members"] table, .widefat' );
		await expect( memberList.first() ).toBeVisible( { timeout: 5000 } );

		// Assert at least one member row exists.
		const memberRows = page.locator( 'table tbody tr, .jetonomy-member-row' );
		await expect( memberRows.first() ).toBeVisible( { timeout: 3000 } );

		// Assert role controls are present (select or badge).
		const roleControl = page.locator( 'select[name*="role"], .jetonomy-role-select, .member-role' );
		await expect( roleControl.first() ).toBeVisible( { timeout: 3000 } );

		// Assert kick/remove button is present.
		const kickBtn = page.locator( 'button:has-text("Remove"), button:has-text("Kick"), a:has-text("Remove"), .jetonomy-kick-member' );
		await expect( kickBtn.first() ).toBeVisible( { timeout: 3000 } );

		const expectation = loadSpec( 'SA06' );
		matchDelivery( expectation, {
			member_list_renders: true,
			role_controls_visible: true,
			kick_button_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
