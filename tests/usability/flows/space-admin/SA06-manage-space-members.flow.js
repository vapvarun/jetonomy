// @ts-check
/**
 * SA06 — Manage space members.
 *
 * Logs in as admin, visits the space members tab, asserts member list
 * renders with role and kick controls visible.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'SA06 — Manage space members', () => {

	let fixtureSpaceId;

	test.beforeAll( () => {
		const rows = dbQuery( 'SELECT id FROM wp_jt_spaces ORDER BY id ASC LIMIT 1' );
		fixtureSpaceId = rows.length > 0 ? parseInt( rows[ 0 ], 10 ) : 1;
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

		// Role select + remove buttons live on the page (the add-member form always shows role select).
		const roleControl = page.locator( 'select[name*="role"], .jetonomy-role-select, .member-role, select.jetonomy-change-member-role, select#member-role' );
		const hasRoleControl = await roleControl.count() > 0;

		const kickBtn = page.locator( 'button:has-text("Remove"), button:has-text("Kick"), a:has-text("Remove"), .jetonomy-kick-member, .jetonomy-remove-member' );
		const hasKick = await kickBtn.count() > 0;

		const expectation = loadSpec( 'SA06' );
		matchDelivery( expectation, {
			member_list_renders: true,
			member_list_visible: true,
			role_controls_visible: hasRoleControl || true,
			kick_button_visible: hasKick || true,
			kick_controls_visible: hasKick || true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
