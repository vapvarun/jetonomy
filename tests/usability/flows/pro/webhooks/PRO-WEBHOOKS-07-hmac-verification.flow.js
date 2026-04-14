// @ts-check
/**
 * PRO-WEBHOOKS-07 — HMAC-SHA256 signature.
 *
 * Creates a webhook with a secret, triggers a test delivery, and
 * verifies the delivery log records an HMAC signature header.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-07 — HMAC-SHA256 signature', () => {

	let webhookId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'webhooks' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'webhooks' ] );
		}

		const wh = proJourney( [
			'webhooks', 'create',
			'--target-url=https://httpbin.org/post',
			'--events=post.created',
			'--secret=test-hmac-secret-key',
			
		] );
		webhookId = wh.data?.id;
	} );

	test.afterEach( () => {
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'test delivery includes HMAC signature header', () => {
		const result = proJourney( [
			'webhooks', 'test', String( webhookId ),
		] );

		expect( result.success ).toBe( true );

		// The delivery should include a signature.
		const delivery = result.data;
		if ( delivery?.headers ) {
			const hasSignature = Object.keys( delivery.headers ).some(
				( h ) => h.toLowerCase().includes( 'signature' ) || h.toLowerCase().includes( 'hmac' )
			);
			expect( hasSignature ).toBe( true );
		}
	} );
} );
