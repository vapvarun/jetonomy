// @ts-check
/**
 * PRO-WEBPUSH-07 — 410 Gone endpoint cleanup.
 *
 * Seeds a subscription with an endpoint that returns 410 Gone, triggers
 * the cleanup cron, and asserts the stale subscription is removed.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowAbsent } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-WEBPUSH-07 — 410 Gone endpoint cleanup', () => {

	test.beforeEach( () => {
		// Seed a subscription that simulates a gone endpoint.
		// Mark it with a fail_count so the cleanup cron picks it up.
		dbWrite( "INSERT INTO wp_jt_pro_push_subscriptions (user_id, endpoint, p256dh_key, auth_key, fail_count, last_error, created_at) VALUES (3, 'https://fcm.googleapis.com/fcm/send/gone-endpoint-07', 'key', 'auth', 5, '410 Gone', NOW())" );
	} );

	test.afterEach( () => {
		try { dbWrite( "DELETE FROM wp_jt_pro_push_subscriptions WHERE endpoint LIKE '%gone-endpoint-07%'" ); } catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'cron removes subscriptions with 410 Gone errors', async () => {
		// Trigger the push cleanup cron.
		wp( [ 'cron', 'event', 'run', 'jetonomy_pro_push_cleanup' ] );

		// DB: the stale subscription should be removed.
		assertDbRowAbsent( 'wp_jt_pro_push_subscriptions', "endpoint LIKE '%gone-endpoint-07%'" );
	} );
} );
