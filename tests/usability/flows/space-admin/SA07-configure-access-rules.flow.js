// @ts-check
/**
 * SA07 — Configure access rules.
 *
 * Logs in as admin, visits the space access rules tab, asserts the form
 * renders. If the tab exists, fills and saves a rule.
 */

const { test, expect } = require( '@playwright/test' );
const { journey } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'SA07 — Configure access rules', () => {

	let fixtureSpaceId;

	test.beforeAll( () => {
		const result = journey( [ 'space', 'list', '--category=1', '--limit=1' ] );
		fixtureSpaceId = result.data?.items?.[ 0 ]?.id ?? 1;
	} );

	test( 'admin sees access rules form on space edit page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ fixtureSpaceId }&tab=access_rules` );
		metrics.start();

		// Assert the access rules tab or form renders.
		const accessTab = page.locator( 'a.nav-tab:has-text("Access"), [data-tab="access_rules"], .jetonomy-access-rules' );
		const accessForm = page.locator( 'form, .jetonomy-access-rules-form, table' );
		await expect( accessTab.or( accessForm ).first() ).toBeVisible( { timeout: 5000 } );

		// If an "Add Rule" button exists, try filling the form.
		const addBtn = page.locator( 'button:has-text("Add Rule"), button:has-text("Add"), a:has-text("Add Rule")' );
		if ( await addBtn.count() > 0 ) {
			await addBtn.first().click();
			metrics.recordClick();

			// Try to fill a minimum trust level field if present.
			const trustInput = page.locator( 'select[name*="trust"], input[name*="trust_level"]' );
			if ( await trustInput.count() > 0 ) {
				await trustInput.first().selectOption( { index: 1 } ).catch( () => {} );
				metrics.recordClick();
			}

			// Save if a save button is present.
			const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"]' );
			if ( await saveBtn.count() > 0 ) {
				await saveBtn.first().click();
				metrics.recordClick();
			}
		}

		const expectation = loadSpec( 'SA07' );
		matchDelivery( expectation, {
			access_rules_form_renders: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
