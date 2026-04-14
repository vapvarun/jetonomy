// @ts-check
/**
 * PRO-WEBHOOKS-01 — Admin create webhook.
 *
 * Creates a webhook subscription via proJourney CLI and verifies
 * it is stored in wp_jt_pro_webhooks.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-WEBHOOKS-01 — Admin create webhook', () => {

	let webhookId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'webhooks' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'webhooks' ] );
		}
	} );

	test.afterEach( () => {
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'create a webhook via CLI and verify DB row', () => {
		const result = proJourney( [
			'webhooks', 'create',
			'--target-url=https://httpbin.org/post',
			'--events=post.created',
			
		] );

		expect( result.success ).toBe( true );
		webhookId = result.data?.id;
		expect( webhookId ).toBeTruthy();

		// Verify row in DB.
		assertDbRowExists( 'wp_jt_pro_webhooks', `id = ${ webhookId }` );

		// Read back.
		const readback = proJourney( [ 'webhooks', 'get', String( webhookId ) ] );
		expect( readback.success ).toBe( true );
		expect( readback.data?.event ).toBe( 'post.created' );
	} );
} );
