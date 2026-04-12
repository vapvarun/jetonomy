// @ts-check
/**
 * PRO-FIELDS-05 — Select option allowlist.
 *
 * Creates a select-type field with a fixed set of options, and asserts
 * the dropdown on the post form only shows those allowed values.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-05 — Select option allowlist', () => {

	const spaceSlug = 'welcome';
	let fieldId;

	test.beforeEach( () => {
		const field = proJourney( [
			'fields', 'create',
			'--name=Category Type',
			'--type=select',
			'--context=post',
			'--options=Bug,Feature,Question',
		] );
		fieldId = field.data?.id;
	} );

	test.afterEach( () => {
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'select field shows only allowed options', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/new/` );
		metrics.start();

		// Find the select field.
		const selectField = page.locator( `select[name="custom_field_${ fieldId }"], select[data-field-id="${ fieldId }"]` );
		await expect( selectField ).toBeVisible( { timeout: 5000 } );

		// Get all option values.
		const options = await selectField.locator( 'option' ).allTextContents();
		const filtered = options.filter( ( o ) => o.trim() !== '' && o.trim() !== '--' );

		// Should contain exactly the allowed values.
		expect( filtered ).toContain( 'Bug' );
		expect( filtered ).toContain( 'Feature' );
		expect( filtered ).toContain( 'Question' );
		expect( filtered.length ).toBe( 3 );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
