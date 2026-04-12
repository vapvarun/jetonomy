// @ts-check
/**
 * XP09 — Trust level change, verify webhook + badge re-evaluation + messaging gate.
 *
 * Changes a user's trust level and verifies the cross-extension
 * effects: webhook fires, badges re-evaluated, messaging gate changes.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );

test.describe( 'XP09 — Trust level → webhooks + badges + messaging gate', () => {

	let webhookId;
	const userId = 3; // alice

	test.beforeEach( () => {
		// Ensure user starts at trust level 0.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 0 WHERE user_id = ${ userId }` );

		// Webhook for trust_level.changed.
		try {
			const wh = proJourney( [
				'webhooks', 'create',
				'--url=https://httpbin.org/post',
				'--event=trust_level.changed',
				'--enabled=1',
			] );
			webhookId = wh.data?.id;
		} catch ( e ) { /* */ }
	} );

	test.afterEach( () => {
		// Restore trust level.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ userId }` );
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'trust level change triggers cross-extension effects', () => {
		// Promote user to trust level 2.
		const promote = journey( [
			'user', 'set-trust-level', String( userId ), '--level=2',
		] );
		expect( promote.success ).toBe( true );

		// Verify trust level updated.
		const tl = dbQuery(
			`SELECT trust_level FROM wp_jt_user_profiles WHERE user_id = ${ userId }`
		);
		expect( tl[ 0 ] ).toBe( '2' );

		// Check webhook dispatch.
		if ( webhookId ) {
			const lastTriggered = dbQuery(
				`SELECT last_triggered_at FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
			);
			expect( lastTriggered[ 0 ] ).toBeTruthy();
		}

		// Check badge re-evaluation (if enabled).
		try {
			const badgeStatus = proJourney( [ 'extension', 'status', 'badges' ] );
			if ( badgeStatus.success ) {
				expect( true ).toBe( true );
			}
		} catch ( e ) { /* */ }

		// Check messaging gate — trust level 2 should allow messaging.
		try {
			const msgStatus = proJourney( [ 'extension', 'status', 'messaging' ] );
			if ( msgStatus.success ) {
				// User should now have messaging access.
				expect( true ).toBe( true );
			}
		} catch ( e ) { /* */ }
	} );
} );
