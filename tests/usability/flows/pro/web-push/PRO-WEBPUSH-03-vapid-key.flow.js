// @ts-check
/**
 * PRO-WEBPUSH-03 — Fetch VAPID public key.
 *
 * Calls the REST endpoint to retrieve the VAPID public key and asserts
 * it returns a valid base64url-encoded string.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../../helpers/wp-cli' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-WEBPUSH-03 — Fetch VAPID public key', () => {

	test.fixme( 'REST endpoint returns a valid VAPID public key', async ( { page } ) => {
		await autoLogin( page, 'alice', '/community/' );

		// Fetch VAPID key from the REST endpoint.
		const response = await page.evaluate( async () => {
			const res = await fetch( '/wp-json/jetonomy/v1/push/vapid-key', {
				credentials: 'same-origin',
			} );
			return {
				status: res.status,
				body: await res.json(),
			};
		} );

		expect( response.status ).toBe( 200 );
		expect( response.body ).toHaveProperty( 'public_key' );

		// VAPID key should be a base64url string (no padding).
		const key = response.body.public_key;
		expect( typeof key ).toBe( 'string' );
		expect( key.length ).toBeGreaterThan( 40 );
		expect( key ).toMatch( /^[A-Za-z0-9_-]+$/ );
	} );
} );
