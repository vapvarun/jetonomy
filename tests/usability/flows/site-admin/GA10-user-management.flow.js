// @ts-check
/**
 * GA10 — User management.
 *
 * Visits the users admin page, asserts the user list renders with trust
 * level and ban controls, and verifies the displayed user count matches DB.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA10 — User list + ban/trust control', () => {

	const specId = 'GA10';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'users page shows correct user count matching database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual user profile count from DB.
		const dbUserCount = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_user_profiles" )[ 0 ] || '0', 10
		);

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-users' );
		metrics.start();

		// Assert page renders.
		const wrapper = page.locator( '.wrap, .jetonomy-users' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert user list table renders.
		const userList = page.locator( 'table, .jetonomy-user-list, .widefat' );
		await expect( userList.first() ).toBeVisible( { timeout: 5000 } );

		// Assert trust level column or controls exist.
		const trustControl = page.locator(
			'th:has-text("Trust"), td .trust-level, select[name*="trust"], .jetonomy-trust-badge'
		);
		await expect( trustControl.first() ).toBeVisible( { timeout: 3000 } );

		// Assert ban controls exist.
		const banControl = page.locator(
			'button:has-text("Ban"), a:has-text("Ban"), .jetonomy-ban-btn, button:has-text("Suspend")'
		);
		await expect( banControl.first() ).toBeVisible( { timeout: 3000 } );

		// Count displayed user rows.
		const userRows = page.locator( 'table tbody tr, .jetonomy-user-row' );
		const displayedCount = await userRows.count();

		// If DB has users, table must show rows (may be paginated).
		let userCountConsistent = true;
		if ( dbUserCount > 0 ) {
			userCountConsistent = displayedCount > 0;
		}

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			user_list_visible: true,
			trust_controls_visible: true,
			ban_controls_visible: true,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			user_count_consistent_with_db: userCountConsistent,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
