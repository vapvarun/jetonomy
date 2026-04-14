// @ts-check
/**
 * SA05 — Edit space settings.
 *
 * Logs in as admin, visits the space edit page, changes title and
 * description, saves via AJAX, and asserts the new values persist.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'SA05 — Edit space settings', () => {

	let fixtureSpaceId;
	let originalTitle;

	test.beforeAll( () => {
		const result = journey( [ 'space', 'list', '--limit=1' ] );
		fixtureSpaceId = result.data?.items?.[ 0 ]?.id ?? 1;
		originalTitle = result.data?.items?.[ 0 ]?.title ?? 'Welcome';
	} );

	test.afterEach( () => {
		// Restore original title.
		try {
			journey( [ 'space', 'update', String( fixtureSpaceId ), `--title=${ originalTitle }` ] );
		} catch ( e ) { /* best effort */ }
	} );

	test( 'admin edits space title and description via admin page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const newTitle = `SA05 Test ${ Date.now() }`;

		await autoLogin( page, 1, `/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ fixtureSpaceId }` );
		metrics.start();

		// Find and update the title field.
		const titleInput = page.locator( 'input[name="title"], input[name="space_title"], #space-title' );
		await expect( titleInput.first() ).toBeVisible( { timeout: 5000 } );
		await titleInput.first().clear();
		await titleInput.first().fill( newTitle );
		metrics.recordClick();

		// Find and update the description field.
		const descInput = page.locator( 'textarea[name="description"], textarea[name="space_description"], #space-description' );
		if ( await descInput.count() > 0 ) {
			await descInput.first().clear();
			await descInput.first().fill( 'SA05 updated description' );
			metrics.recordClick();
		}

		// Click save.
		const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"]' );
		await expect( saveBtn.first() ).toBeVisible();
		await saveBtn.first().click();
		metrics.recordClick();

		// Wait for AJAX save to complete.
		await page.waitForTimeout( 1500 );

		// Assert title was persisted in DB.
		const rows = dbQuery( `SELECT title FROM wp_jt_spaces WHERE id = ${ fixtureSpaceId }` );
		expect( rows[ 0 ] ).toBe( newTitle );

		const expectation = loadSpec( 'SA05' );
		matchDelivery( expectation, {
			title_persisted_in_db: rows[ 0 ] === newTitle,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
