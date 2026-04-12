// @ts-check
/**
 * PRO-WEBHOOKS-05 — Failure increments fail_count.
 *
 * Simulates a failed delivery and verifies the webhook's fail_count
 * column increments.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-05 — Failure increments fail_count', () => {

	let webhookId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'webhooks' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'webhooks' ] );
		}

		// Create a webhook pointing to an invalid URL that will fail.
		const wh = proJourney( [
			'webhooks', 'create',
			'--url=https://invalid.test.local/webhook',
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

	test( 'fail_count increments on delivery failure', () => {
		const before = dbQuery(
			`SELECT fail_count FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
		);
		const countBefore = parseInt( before[ 0 ] || '0', 10 );

		// Attempt a test delivery (will fail because URL is unreachable).
		try {
			proJourney( [ 'webhooks', 'test', String( webhookId ) ] );
		} catch ( e ) {
			// Expected to fail.
		}

		const after = dbQuery(
			`SELECT fail_count FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
		);
		const countAfter = parseInt( after[ 0 ] || '0', 10 );

		expect( countAfter ).toBeGreaterThan( countBefore );
	} );
} );
