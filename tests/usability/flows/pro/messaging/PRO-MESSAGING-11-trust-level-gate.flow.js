// @ts-check
/**
 * PRO-MESSAGING-11 — Trust level gate on send.
 *
 * Creates a user with trust_level 0, attempts to send a message,
 * and verifies the system blocks the action with a clear error.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite, dbQuery } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-11 — Trust level gate on send', () => {

	test.beforeEach( () => {
		// Set alice to trust_level 0 — below messaging threshold.
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 0 WHERE user_id = 3" );
	} );

	test.afterEach( () => {
		// Restore alice trust level.
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );
	} );

	test.fixme( 'trust level 0 user cannot send messages', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		// The compose button should either be hidden or disabled.
		const composeBtn = page.locator( 'button:has-text("New Message"), a:has-text("New Message")' );
		const btnCount = await composeBtn.count();

		if ( btnCount > 0 ) {
			// If visible, it should be disabled or clicking shows a gate message.
			const isDisabled = await composeBtn.isDisabled();
			if ( ! isDisabled ) {
				await composeBtn.click();
				metrics.recordClick();

				// Should show trust-level gate message.
				const gateMsg = page.locator( '.jt-trust-gate, .jt-permission-denied' );
				await expect( gateMsg ).toBeVisible( { timeout: 5000 } );
			}
		}

		// Verify via CLI that the API also blocks it.
		try {
			const result = proJourney( [
				'messaging', 'create-conversation',
				'--creator=3',
				'--participants=4',
				'--subject=Should Fail',
			] );
			// If it succeeds, the gate is broken.
			expect( result.success ).toBe( false );
		} catch ( e ) {
			// Expected — the command should fail.
			expect( e.message ).toMatch( /trust|permission|denied/i );
		}

		metrics.assertErrorCount( 0 );
	} );
} );
