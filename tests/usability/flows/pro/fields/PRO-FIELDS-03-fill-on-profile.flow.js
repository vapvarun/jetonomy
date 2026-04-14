// @ts-check
/**
 * PRO-FIELDS-03 — Fill profile field.
 *
 * Seeds a custom profile field, logs in as alice, visits her profile
 * edit page, fills the field, saves, and asserts the value is stored.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-03 — Fill profile field', () => {

	let fieldId;

	test.beforeEach( () => {
		const field = proJourney( [ 'fields', 'create', '--name=Favorite Language', '--type=text', '--context=profile' ] );
		fieldId = field.data?.id;
	} );

	test.afterEach( () => {
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice fills a custom profile field on edit page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/u/alice/edit/' );
		metrics.start();

		// Find the custom field input.
		const customField = page.locator( `input[name="custom_field_${ fieldId }"], input[data-field-id="${ fieldId }"]` );
		await expect( customField ).toBeVisible( { timeout: 5000 } );
		await customField.fill( 'JavaScript' );

		// Save.
		const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"]' );
		await saveBtn.click();
		metrics.recordClick();

		// Wait for success feedback.
		const notice = page.locator( '.jt-notice-success, .jt-toast-success, .notice-success' );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		// DB: value stored.
		assertDbRowExists( 'wp_jt_pro_field_values', `field_id = ${ fieldId } AND entity_type = 'user' AND entity_id = 3` );

		// Value is correct.
		const val = dbQuery( `SELECT value FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId } AND entity_id = 3` );
		expect( val[ 0 ] ).toBe( 'JavaScript' );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
