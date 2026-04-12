// @ts-check
/**
 * GA06 — Category CRUD.
 *
 * Visits the categories admin page and asserts the category list and
 * add form render correctly.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA06 — Category CRUD', () => {

	test( 'categories page renders with list and add form', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-categories' );
		metrics.start();

		// Assert the page wrapper renders.
		const wrapper = page.locator( '.wrap, .jetonomy-categories' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert the category list table or container renders.
		const categoryList = page.locator( 'table, .jetonomy-category-list, .widefat' );
		await expect( categoryList.first() ).toBeVisible( { timeout: 5000 } );

		// Assert the add category form renders.
		const addForm = page.locator(
			'form, input[name="name"], input[name="category_name"], #category-name'
		);
		await expect( addForm.first() ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
