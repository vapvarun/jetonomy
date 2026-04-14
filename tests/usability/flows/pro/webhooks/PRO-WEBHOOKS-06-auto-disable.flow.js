// @ts-check
/**
 * PRO-WEBHOOKS-06 — Auto-disable at threshold.
 *
 * Sets a webhook's fail_count just below the auto-disable threshold,
 * triggers one more failure, and verifies the webhook is auto-disabled.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-06 — Auto-disable at threshold', () => {

	let webhookId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'webhooks' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'webhooks' ] );
		}

		const wh = proJourney( [
			'webhooks', 'create',
			'--target-url=https://invalid.test.local/webhook',
			'--events=post.created',
			
		] );
		webhookId = wh.data?.id;

		// Set fail_count to threshold - 1 (threshold is typically 5 or 10).
		dbWrite(
			`UPDATE wp_jt_pro_webhooks SET fail_count = 9 WHERE id = ${ webhookId }`
		);
	} );

	test.afterEach( () => {
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'webhook is auto-disabled after reaching fail threshold', () => {
		// One more failure should push it over the threshold.
		try {
			proJourney( [ 'webhooks', 'test', String( webhookId ) ] );
		} catch ( e ) {
			// Expected.
		}

		const enabled = dbQuery(
			`SELECT enabled FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
		);
		expect( enabled[ 0 ] ).toBe( '0' );
	} );
} );
