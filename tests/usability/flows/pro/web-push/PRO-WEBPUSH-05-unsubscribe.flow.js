// @ts-check
/**
 * PRO-WEBPUSH-05 — Unsubscribe device.
 *
 * Seeds a push subscription for alice, opens settings, clicks
 * unsubscribe, and asserts the subscription row is removed.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WEBPUSH-05 — Unsubscribe device', () => {

	test.beforeEach( () => {
		// Seed a subscription.
		dbWrite( "INSERT INTO wp_jt_pro_push_subscriptions (user_id, endpoint, p256dh_key, auth_key, created_at) VALUES (3, 'https://fcm.googleapis.com/fcm/send/test-unsub-05', 'p256dh', 'auth', NOW())" );
	} );

	test.afterEach( () => {
		try { dbWrite( "DELETE FROM wp_jt_pro_push_subscriptions WHERE endpoint LIKE '%test-unsub-05%'" ); } catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'alice unsubscribes from push notifications', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Mock PushManager.getSubscription to return our fake sub.
		await page.addInitScript( () => {
			if ( 'serviceWorker' in navigator ) {
				const origRegister = navigator.serviceWorker.register;
				navigator.serviceWorker.register = async function( ...args ) {
					const reg = await origRegister.apply( this, args );
					reg.pushManager.getSubscription = async () => ( {
						endpoint: 'https://fcm.googleapis.com/fcm/send/test-unsub-05',
						unsubscribe: async () => true,
						toJSON: () => ( { endpoint: 'https://fcm.googleapis.com/fcm/send/test-unsub-05' } ),
					} );
					return reg;
				};
			}
		} );

		await autoLogin( page, 'alice', '/community/u/alice/edit/' );
		metrics.start();

		// Click disable/unsubscribe button.
		const unsubBtn = page.locator( 'button:has-text("Disable Push"), button:has-text("Unsubscribe")' );
		await expect( unsubBtn ).toBeVisible( { timeout: 5000 } );
		await unsubBtn.click();
		metrics.recordClick();

		// Success indicator.
		const successEl = page.locator( '.jt-push-unsubscribed, text=/unsubscribed|disabled/i' );
		await expect( successEl ).toBeVisible( { timeout: 10000 } );

		// DB: subscription removed.
		assertDbRowAbsent( 'wp_jt_pro_push_subscriptions', "endpoint LIKE '%test-unsub-05%'" );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
