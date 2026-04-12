// @ts-check
/**
 * PRO-FIELDS-01 — Admin create field.
 *
 * Logs in as admin, navigates to the custom fields admin page,
 * creates a new text field, and asserts it is stored in wp_jt_pro_fields.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-01 — Admin create field', () => {

	let fieldId;

	test.afterEach( () => {
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin creates a custom text field', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const fieldName = `Test Field ${ Date.now() }`;

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-fields' );
		metrics.start();

		// Click Add Field.
		const addBtn = page.locator( 'button:has-text("Add Field"), a:has-text("Add Field")' );
		if ( await addBtn.isVisible() ) {
			await addBtn.click();
			metrics.recordClick();
		}

		// Fill name.
		const nameInput = page.locator( 'input[name="field_name"], input[name="name"]' );
		await expect( nameInput ).toBeVisible( { timeout: 5000 } );
		await nameInput.fill( fieldName );

		// Select type = text.
		const typeSelect = page.locator( 'select[name="field_type"], select[name="type"]' );
		if ( await typeSelect.isVisible() ) {
			await typeSelect.selectOption( 'text' );
		}

		// Select context = post.
		const contextSelect = page.locator( 'select[name="field_context"], select[name="context"]' );
		if ( await contextSelect.isVisible() ) {
			await contextSelect.selectOption( 'post' );
		}

		// Save.
		const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"]' );
		await saveBtn.click();
		metrics.recordClick();

		const notice = page.locator( '.notice-success, .updated' );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		// DB: field exists.
		const ids = dbQuery( `SELECT id FROM wp_jt_pro_fields WHERE name LIKE '%${ fieldName.slice( 0, 10 ) }%' LIMIT 1` );
		expect( ids.length ).toBeGreaterThan( 0 );
		fieldId = parseInt( ids[ 0 ], 10 );

		assertDbRowExists( 'wp_jt_pro_fields', `id = ${ fieldId }` );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
