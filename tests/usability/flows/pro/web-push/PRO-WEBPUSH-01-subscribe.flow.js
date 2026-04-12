// @ts-check
/**
 * PRO-WEBPUSH-01 — Grant permission + subscribe.
 *
 * Logs in as alice, mocks the push API permission grant, subscribes
 * to push notifications, and asserts a subscription row is stored in
 * wp_jt_pro_push_subscriptions.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WEBPUSH-01 — Grant permission + subscribe', () => {

	test.afterEach( () => {
		// Cleanup alice subscriptions.
		try { dbWrite( "DELETE FROM wp_jt_pro_push_subscriptions WHERE user_id = 3" ); } catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'alice subscribes to push notifications', async ( { page, context } ) => {
		const metrics = new EaseMetrics( page );

		// Grant notification permission before navigating.
		await context.grantPermissions( [ 'notifications' ] );

		// Mock the PushManager.subscribe to return a fake subscription.
		await page.addInitScript( () => {
			const fakeSubscription = {
				endpoint: 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
				expirationTime: null,
				getKey: ( name ) => {
					const keys = { p256dh: new Uint8Array( 65 ), auth: new Uint8Array( 16 ) };
					return keys[ name ] || null;
				},
				toJSON: () => ( {
					endpoint: 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
					keys: { p256dh: 'test-p256dh', auth: 'test-auth' },
				} ),
				unsubscribe: async () => true,
			};
			if ( 'serviceWorker' in navigator ) {
				const origRegister = navigator.serviceWorker.register;
				navigator.serviceWorker.register = async function( ...args ) {
					const reg = await origRegister.apply( this, args );
					reg.pushManager.subscribe = async () => fakeSubscription;
					reg.pushManager.getSubscription = async () => null;
					return reg;
				};
			}
		} );

		await autoLogin( page, 'alice', '/community/u/alice/edit/' );
		metrics.start();

		// Click "Enable Push Notifications" button.
		const enableBtn = page.locator( 'button:has-text("Enable Push"), button:has-text("Subscribe")' );
		await expect( enableBtn ).toBeVisible( { timeout: 5000 } );
		await enableBtn.click();
		metrics.recordClick();

		// Success indicator.
		const successEl = page.locator( '.jt-push-subscribed, text=/subscribed|enabled/i' );
		await expect( successEl ).toBeVisible( { timeout: 10000 } );

		// DB: subscription row exists.
		assertDbRowExists( 'wp_jt_pro_push_subscriptions', "user_id = 3" );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertErrorCount( 0 );
	} );
} );
