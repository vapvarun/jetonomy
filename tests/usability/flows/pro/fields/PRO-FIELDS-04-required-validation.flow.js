// @ts-check
/**
 * PRO-FIELDS-04 — Required field validation.
 *
 * Seeds a required custom field, alice tries to create a post without
 * filling it, and asserts the form shows a validation error.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-04 — Required field validation', () => {

	const spaceSlug = 'welcome';
	let fieldId;

	test.beforeEach( () => {
		const field = proJourney( [ 'fields', 'create', '--name=Department', '--type=text', '--context=post', '--required=true' ] );
		fieldId = field.data?.id;
	} );

	test.afterEach( () => {
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'empty required field blocks post submission', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/new/` );
		metrics.start();

		// Fill title and content but leave the custom field empty.
		const titleInput = page.locator( '[name="title"], .jt-post-title input' );
		await titleInput.fill( `Required Test ${ Date.now() }` );

		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' );
		await editorBody.click();
		await page.keyboard.type( 'Missing required field.' );

		// Submit without filling the required custom field.
		const submitBtn = page.locator( 'button:has-text("Publish"), button:has-text("Create Post")' );
		await submitBtn.click();
		metrics.recordClick();

		// Should NOT navigate away — validation error should show.
		const error = page.locator( '.jt-field-error, .jt-validation-error, [role="alert"]' );
		await expect( error ).toBeVisible( { timeout: 5000 } );

		// URL should still be /new/ (not redirected to a post).
		expect( page.url() ).toContain( '/new/' );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
