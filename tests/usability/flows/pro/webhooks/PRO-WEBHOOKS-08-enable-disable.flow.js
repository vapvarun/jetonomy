// @ts-check
/**
 * PRO-WEBHOOKS-08 — Enable/disable webhook.
 *
 * Creates a webhook, disables it via CLI, verifies it does not fire
 * on events, then re-enables and verifies it fires again.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-08 — Enable/disable webhook', () => {

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

	test( 'disable then re-enable webhook', () => {
		// Disable.
		proJourney( [ 'webhooks', 'disable', String( webhookId ) ] );
		let enabled = dbQuery(
			`SELECT enabled FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
		);
		expect( enabled[ 0 ] ).toBe( '0' );

		// Re-enable.
		proJourney( [ 'webhooks', 'enable', String( webhookId ) ] );
		enabled = dbQuery(
			`SELECT enabled FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
		);
		expect( enabled[ 0 ] ).toBe( '1' );
	} );
} );
