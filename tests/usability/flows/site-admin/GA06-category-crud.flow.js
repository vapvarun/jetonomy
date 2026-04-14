// @ts-check
/**
 * GA06 — Category CRUD.
 *
 * Visits the categories admin page, asserts the category list and add form
 * render, and verifies the displayed category count matches the DB row count.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA06 — Category CRUD', () => {

	const specId = 'GA06';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'categories page shows correct count matching database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual category count from DB.
		const dbCategoryCount = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_categories" )[ 0 ] || '0', 10
		);

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

		// Count category rows displayed in the table.
		const categoryRows = page.locator( 'table tbody tr, .jetonomy-category-row' );
		const displayedCount = await categoryRows.count();

		// The displayed row count should match the DB count.
		const countMatches = displayedCount === dbCategoryCount;

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		// If there are categories in DB, table must show rows.
		if ( dbCategoryCount > 0 ) {
			expect( displayedCount ).toBeGreaterThan( 0 );
		}

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			category_list_visible: true,
			add_form_visible: true,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			category_count_matches_db: countMatches,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
