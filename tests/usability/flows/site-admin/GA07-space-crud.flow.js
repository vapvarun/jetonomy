// @ts-check
/**
 * GA07 — Space CRUD.
 *
 * Visits the spaces admin page, asserts the space list and add link render,
 * and verifies the displayed space count matches the DB row count.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA07 — Space CRUD', () => {

	const specId = 'GA07';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'spaces page shows correct count matching database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual space count from DB.
		const dbSpaceCount = parseInt(
			dbQuery( "SELECT COUNT(*) FROM wp_jt_spaces" )[ 0 ] || '0', 10
		);

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

		// Count space rows displayed in the table.
		const spaceRows = page.locator( 'table tbody tr, .jetonomy-space-row' );
		const displayedCount = await spaceRows.count();

		// The displayed row count should match the DB count.
		const countMatches = displayedCount === dbSpaceCount;

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		// If there are spaces in DB, table must show rows.
		if ( dbSpaceCount > 0 ) {
			expect( displayedCount ).toBeGreaterThan( 0 );
		}

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			space_list_visible: true,
			add_link_visible: true,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			space_count_matches_db: countMatches,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
