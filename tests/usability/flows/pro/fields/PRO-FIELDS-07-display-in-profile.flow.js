// @ts-check
/**
 * PRO-FIELDS-07 — Display fields in profile.
 *
 * Seeds a profile field with a value for bob, visits bob's profile
 * as alice, and asserts the field renders in the profile section.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-07 — Display fields in profile', () => {

	let fieldId;

	test.beforeEach( () => {
		const field = proJourney( [ 'fields', 'create', '--name=Location', '--type=text', '--context=profile' ] );
		fieldId = field.data?.id;

		// Set value for bob (user 4).
		if ( fieldId ) {
			dbWrite( `INSERT INTO wp_jt_pro_field_values (field_id, entity_type, entity_id, value) VALUES (${ fieldId }, 'user', 4, 'San Francisco')` );
		}
	} );

	test.afterEach( () => {
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'custom profile field renders on user profile page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/u/bob/' );
		metrics.start();

		// Profile fields section.
		const fieldsSection = page.locator( '.jt-profile-fields, .jt-user-fields' );
		await expect( fieldsSection ).toBeVisible( { timeout: 5000 } );

		// Label and value.
		await expect( fieldsSection.locator( 'text=Location' ) ).toBeVisible();
		await expect( fieldsSection.locator( 'text=San Francisco' ) ).toBeVisible();

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
