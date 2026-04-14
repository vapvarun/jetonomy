// @ts-check
/**
 * PRO-FIELDS-08 — Admin delete field.
 *
 * Admin deletes a custom field and asserts both the field definition
 * and all stored values are removed (cascade).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-08 — Admin delete field', () => {

	let fieldId;

	test.beforeEach( () => {
		const field = proJourney( [ 'fields', 'create', '--name=Delete Me Field', '--type=text', '--context=post' ] );
		fieldId = field.data?.id;

		// Seed a value so we verify cascade.
		if ( fieldId ) {
			dbWrite( `INSERT INTO wp_jt_pro_field_values (field_id, entity_type, entity_id, value) VALUES (${ fieldId }, 'post', 1, 'tmp')` );
		}
	} );

	test.afterEach( () => {
		// Safety cleanup.
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin deletes a field and values are cascade-removed', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-fields' );
		metrics.start();

		// Find and click delete for this field.
		const deleteBtn = page.locator( `button[data-field-id="${ fieldId }"][data-action="delete"], a[href*="delete"][href*="${ fieldId }"]` );
		await expect( deleteBtn ).toBeVisible( { timeout: 5000 } );
		await deleteBtn.click();
		metrics.recordClick();

		// Handle confirmation.
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await expect( deleteBtn ).not.toBeVisible( { timeout: 5000 } );

		// DB: field and values removed.
		assertDbRowAbsent( 'wp_jt_pro_fields', `id = ${ fieldId }` );
		assertDbRowAbsent( 'wp_jt_pro_field_values', `field_id = ${ fieldId }` );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
