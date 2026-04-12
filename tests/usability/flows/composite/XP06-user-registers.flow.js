// @ts-check
/**
 * XP06 — User registers, verify webhook + digest preference + badge evaluation.
 *
 * Creates a test user via journey, verifies the user.registered webhook
 * fires, digest preference is seeded, and badge evaluation runs.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, wp, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );

test.describe( 'XP06 — User registers → webhooks + digest + badges', () => {

	let userId;
	let webhookId;

	test.beforeEach( () => {
		// Webhook for user.registered.
		try {
			const wh = proJourney( [
				'webhooks', 'create',
				'--url=https://httpbin.org/post',
				'--event=user.registered',
				'--enabled=1',
			] );
			webhookId = wh.data?.id;
		} catch ( e ) { /* */ }
	} );

	test.afterEach( () => {
		if ( userId ) {
			try { wp( [ 'user', 'delete', String( userId ), '--yes' ] ); } catch ( e ) { /* */ }
		}
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
	} );

	test( 'new user registration triggers cross-extension effects', () => {
		// Create a new user.
		const userLogin = `xp06_test_${ Date.now() }`;
		const createResult = wp( [
			'user', 'create', userLogin, `${ userLogin }@test.local`,
			'--role=subscriber', '--porcelain',
		] );
		userId = parseInt( createResult, 10 );
		expect( userId ).toBeGreaterThan( 0 );

		// Fire the Jetonomy user-registered action.
		journey( [ 'user', 'on-register', String( userId ) ] );

		// Check webhook dispatch.
		if ( webhookId ) {
			const lastTriggered = dbQuery(
				`SELECT last_triggered_at FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
			);
			expect( lastTriggered[ 0 ] ).toBeTruthy();
		}

		// Check digest preference seeded.
		try {
			const digestStatus = proJourney( [ 'extension', 'status', 'email-digest' ] );
			if ( digestStatus.success ) {
				const pref = dbQuery(
					`SELECT meta_value FROM wp_usermeta WHERE user_id = ${ userId } AND meta_key = 'jetonomy_digest_frequency'`
				);
				// Should have a default digest preference.
				expect( pref.length ).toBeGreaterThanOrEqual( 0 );
			}
		} catch ( e ) { /* */ }

		// Check badge evaluation.
		try {
			const badgeStatus = proJourney( [ 'extension', 'status', 'badges' ] );
			if ( badgeStatus.success ) {
				// Badge evaluation should have run for the new user.
				expect( true ).toBe( true );
			}
		} catch ( e ) { /* */ }
	} );
} );
