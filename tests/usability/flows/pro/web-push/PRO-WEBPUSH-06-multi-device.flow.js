// @ts-check
/**
 * PRO-WEBPUSH-06 — Multi-device same user.
 *
 * Seeds two distinct push subscriptions for alice (simulating two
 * devices) and verifies both rows coexist in the DB.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-WEBPUSH-06 — Multi-device same user', () => {

	test.beforeEach( () => {
		// Seed two subscriptions for alice (two devices).
		dbWrite( "INSERT INTO wp_jt_pro_push_subscriptions (user_id, endpoint, p256dh_key, auth_key, created_at) VALUES (3, 'https://fcm.googleapis.com/fcm/send/device-a-06', 'key-a', 'auth-a', NOW())" );
		dbWrite( "INSERT INTO wp_jt_pro_push_subscriptions (user_id, endpoint, p256dh_key, auth_key, created_at) VALUES (3, 'https://fcm.googleapis.com/fcm/send/device-b-06', 'key-b', 'auth-b', NOW())" );
	} );

	test.afterEach( () => {
		try { dbWrite( "DELETE FROM wp_jt_pro_push_subscriptions WHERE endpoint LIKE '%device-%-06%'" ); } catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'alice can have multiple push subscriptions (multi-device)', async () => {
		// DB: both subscriptions exist.
		assertDbRowExists( 'wp_jt_pro_push_subscriptions', "user_id = 3 AND endpoint LIKE '%device-a-06%'" );
		assertDbRowExists( 'wp_jt_pro_push_subscriptions', "user_id = 3 AND endpoint LIKE '%device-b-06%'" );

		// Total count for alice = 2.
		const count = dbQuery( "SELECT COUNT(*) FROM wp_jt_pro_push_subscriptions WHERE user_id = 3 AND endpoint LIKE '%device-%-06%'" );
		expect( parseInt( count[ 0 ], 10 ) ).toBe( 2 );
	} );
} );
