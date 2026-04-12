// @ts-check
/**
 * GA10 — User management.
 *
 * Visits the users admin page and asserts the user list renders with
 * trust level and ban controls.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA10 — User list + ban/trust control', () => {

	test( 'users page renders with trust and ban controls', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

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

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
