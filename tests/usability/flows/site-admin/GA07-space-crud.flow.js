// @ts-check
/**
 * GA07 — Space CRUD.
 *
 * Visits the spaces admin page and asserts the space list and add link
 * render correctly.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA07 — Space CRUD', () => {

	test( 'spaces page renders with list and add link', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-spaces' );
		metrics.start();

		// Assert page renders.
		const wrapper = page.locator( '.wrap, .jetonomy-spaces' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert the space list table renders.
		const spaceList = page.locator( 'table, .jetonomy-space-list, .widefat' );
		await expect( spaceList.first() ).toBeVisible( { timeout: 5000 } );

		// Assert the "Add New" link or button exists.
		const addLink = page.locator(
			'a:has-text("Add New"), a:has-text("Add Space"), button:has-text("Add"), .page-title-action'
		);
		await expect( addLink.first() ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
