// @ts-check
/**
 * PRO-MESSAGING-05 — Open conversation thread.
 *
 * Seeds a conversation with messages, logs in as alice, clicks the
 * conversation row in the inbox, and asserts messages render in the thread.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-05 — Open conversation thread', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4',
			'--participants=3',
			'--subject=Thread Test',
		] );
		conversationId = conv.data?.id;

		// Add a message from bob.
		if ( conversationId ) {
			proJourney( [
				'messaging', 'send-message',
				`--conversation=${ conversationId }`,
				'--sender=4',
				'--body=Hello alice, how are you?',
			] );
		}
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice opens a conversation and sees messages', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		// Click the conversation row.
		const row = page.locator( '.jt-conversation-row' ).first();
		await expect( row ).toBeVisible( { timeout: 5000 } );
		await row.click();
		metrics.recordClick();

		// Wait for the conversation thread to load.
		await page.waitForURL( /\/community\/messages\/\d+/, { timeout: 10000 } );

		// Messages should be visible.
		const message = page.locator( '.jt-message-bubble', { hasText: 'Hello alice' } );
		await expect( message ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
