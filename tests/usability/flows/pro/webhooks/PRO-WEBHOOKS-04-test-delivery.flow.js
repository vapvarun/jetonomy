// @ts-check
/**
 * PRO-WEBHOOKS-04 — Admin test delivery.
 *
 * Uses the proJourney CLI to send a test delivery for an existing
 * webhook and verifies the command succeeds.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-04 — Admin test delivery', () => {

	let webhookId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'webhooks' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'webhooks' ] );
		}

		const wh = proJourney( [
			'webhooks', 'create',
			'--url=https://httpbin.org/post',
			'--event=post.created',
			'--enabled=1',
		] );
		webhookId = wh.data?.id;
	} );

	test.afterEach( () => {
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'test delivery command completes without error', () => {
		const result = proJourney( [
			'webhooks', 'test', String( webhookId ),
		] );

		// The test delivery should succeed (HTTP mock via pre_http_request).
		expect( result.success ).toBe( true );
	} );
} );
